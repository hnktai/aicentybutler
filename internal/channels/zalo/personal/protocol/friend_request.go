package protocol

import (
	"bytes"
	"context"
	"encoding/json"
	"fmt"
	"net/http"
	"strings"
)

// FriendRequest is an inbound Zalo personal friend request.
type FriendRequest struct {
	FromUID string
	ToUID   string
	Message string
}

func parseFriendRequest(raw json.RawMessage) (FriendRequest, error) {
	raw = bytes.TrimSpace(raw)
	if len(raw) == 0 || bytes.Equal(raw, []byte("null")) {
		return FriendRequest{}, fmt.Errorf("empty friend request payload")
	}
	if raw[0] == '"' {
		var encoded string
		if err := json.Unmarshal(raw, &encoded); err != nil {
			return FriendRequest{}, fmt.Errorf("decode friend request wrapper: %w", err)
		}
		raw = []byte(encoded)
	}
	var payload struct {
		FromUID json.RawMessage `json:"fromUid"`
		ToUID   json.RawMessage `json:"toUid"`
		Message string          `json:"message"`
	}
	if err := json.Unmarshal(raw, &payload); err != nil {
		return FriendRequest{}, fmt.Errorf("decode friend request: %w", err)
	}
	request := FriendRequest{
		FromUID: strings.TrimSpace(rawJSONToDecimalString(payload.FromUID)),
		ToUID:   strings.TrimSpace(rawJSONToDecimalString(payload.ToUID)),
		Message: payload.Message,
	}
	if request.FromUID == "" {
		return FriendRequest{}, fmt.Errorf("friend request missing fromUid")
	}
	return request, nil
}

// AcceptFriendRequest accepts an inbound friend request using the authenticated
// personal-account session. This endpoint is part of Zalo's unofficial web protocol.
func AcceptFriendRequest(ctx context.Context, sess *Session, friendID string) error {
	if sess == nil {
		return fmt.Errorf("zalo_personal: session is nil")
	}
	friendID = strings.TrimSpace(friendID)
	if friendID == "" {
		return fmt.Errorf("zalo_personal: friend ID is empty")
	}
	baseURL := getServiceURL(sess, "friend")
	if baseURL == "" {
		return fmt.Errorf("zalo_personal: no friend service URL")
	}
	payload := map[string]any{
		"fid":      friendID,
		"language": sess.Language,
	}
	encData, err := encryptPayload(sess, payload)
	if err != nil {
		return fmt.Errorf("zalo_personal: encrypt accept-friend payload: %w", err)
	}
	reqURL := makeURL(sess, baseURL+"/api/friend/accept", nil, false)
	req, err := http.NewRequestWithContext(ctx, http.MethodPost, reqURL,
		buildFormBody(map[string]string{"params": encData}))
	if err != nil {
		return err
	}
	setDefaultHeaders(req, sess)
	resp, err := sess.Client.Do(req)
	if err != nil {
		return fmt.Errorf("zalo_personal: accept friend request: %w", err)
	}
	defer resp.Body.Close()
	var envelope Response[*string]
	if err := readJSON(resp, &envelope); err != nil {
		return fmt.Errorf("zalo_personal: parse accept-friend response: %w", err)
	}
	if envelope.ErrorCode != 0 {
		return fmt.Errorf("zalo_personal: accept-friend error code %d: %s", envelope.ErrorCode, envelope.ErrorMessage)
	}
	if envelope.Data != nil {
		if _, err := decryptDataField(sess, *envelope.Data); err != nil {
			return fmt.Errorf("zalo_personal: decrypt accept-friend response: %w", err)
		}
	}
	return nil
}
