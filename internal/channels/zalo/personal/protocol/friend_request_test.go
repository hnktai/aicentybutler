package protocol

import (
	"context"
	"encoding/base64"
	"encoding/json"
	"net/http"
	"net/http/httptest"
	"strings"
	"testing"
	"time"
)

func TestHandleControlEventsEmitsFriendRequestV2(t *testing.T) {
	listener := &Listener{
		sess:            &Session{UID: "bot-uid"},
		friendRequestCh: make(chan FriendRequest, 1),
		errorCh:         make(chan error, 1),
	}
	payload := `{"data":{"controls":[{"content":{"act_type":"fr","act":"req_v2","data":"{\"fromUid\":\"6631971342486485220\",\"toUid\":\"bot-uid\",\"message\":\"Xin ket ban\"}"}}]}}`

	listener.handleControlEvents(context.Background(), payload, 0)

	select {
	case request := <-listener.FriendRequests():
		if request.FromUID != "6631971342486485220" {
			t.Fatalf("FromUID = %q", request.FromUID)
		}
		if request.ToUID != "bot-uid" || request.Message != "Xin ket ban" {
			t.Fatalf("unexpected request: %+v", request)
		}
	case err := <-listener.Errors():
		t.Fatalf("unexpected listener error: %v", err)
	case <-time.After(time.Second):
		t.Fatal("friend request was not emitted")
	}
}

func TestHandleControlEventsIgnoresLegacyDuplicateFriendRequest(t *testing.T) {
	listener := &Listener{
		sess:            &Session{UID: "bot-uid"},
		friendRequestCh: make(chan FriendRequest, 1),
		errorCh:         make(chan error, 1),
	}
	payload := `{"data":{"controls":[{"content":{"act_type":"fr","act":"req","data":{"fromUid":"6631971342486485220","toUid":"bot-uid"}}}]}}`

	listener.handleControlEvents(context.Background(), payload, 0)

	select {
	case request := <-listener.FriendRequests():
		t.Fatalf("legacy duplicate emitted: %+v", request)
	default:
	}
}

func TestAcceptFriendRequestUsesFriendEndpointAndEncryptedPayload(t *testing.T) {
	key := []byte("1234567890abcdef")
	var gotFID string
	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if r.URL.Path != "/api/friend/accept" {
			t.Errorf("path = %q", r.URL.Path)
		}
		if r.Method != http.MethodPost {
			t.Errorf("method = %s", r.Method)
		}
		if err := r.ParseForm(); err != nil {
			t.Errorf("ParseForm: %v", err)
		}
		plain, err := DecodeAESCBC(key, r.Form.Get("params"))
		if err != nil {
			t.Errorf("decrypt params: %v", err)
		}
		var params map[string]any
		if err := json.Unmarshal(plain, &params); err != nil {
			t.Errorf("decode params: %v", err)
		}
		gotFID, _ = params["fid"].(string)
		if params["language"] != "vi" {
			t.Errorf("language = %v", params["language"])
		}

		inner, err := EncodeAESCBC(key, `{"error_code":0,"error_message":"","data":""}`, false)
		if err != nil {
			t.Errorf("encrypt response: %v", err)
		}
		_ = json.NewEncoder(w).Encode(map[string]any{
			"error_code": 0,
			"data":       inner,
		})
	}))
	defer server.Close()

	sess := NewSession()
	sess.Client = server.Client()
	sess.IMEI = "test-imei"
	sess.Language = "vi"
	sess.SecretKey = base64.StdEncoding.EncodeToString(key)
	sess.LoginInfo = &LoginInfo{ZpwServiceMapV3: ZpwServiceMapV3{Friend: []string{server.URL}}}

	if err := AcceptFriendRequest(context.Background(), sess, "6631971342486485220"); err != nil {
		t.Fatal(err)
	}
	if gotFID != "6631971342486485220" {
		t.Fatalf("fid = %q", gotFID)
	}
}

func TestAcceptFriendRequestFailsClosedOnZaloError(t *testing.T) {
	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, _ *http.Request) {
		_ = json.NewEncoder(w).Encode(map[string]any{
			"error_code":    215,
			"error_message": "blocked",
		})
	}))
	defer server.Close()

	key := []byte("1234567890abcdef")
	sess := NewSession()
	sess.Client = server.Client()
	sess.SecretKey = base64.StdEncoding.EncodeToString(key)
	sess.LoginInfo = &LoginInfo{ZpwServiceMapV3: ZpwServiceMapV3{Friend: []string{server.URL}}}

	err := AcceptFriendRequest(context.Background(), sess, "6631971342486485220")
	if err == nil || !strings.Contains(err.Error(), "error code 215") {
		t.Fatalf("error = %v", err)
	}
}
