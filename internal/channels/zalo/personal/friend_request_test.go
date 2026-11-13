package personal

import (
	"context"
	"errors"
	"sync/atomic"
	"testing"

	"github.com/google/uuid"

	"github.com/nextlevelbuilder/goclaw/internal/channels/zalo/personal/protocol"
	"github.com/nextlevelbuilder/goclaw/internal/config"
)

type stubZaloFriendVerifier struct {
	verified bool
	err      error
	calls    atomic.Int32
}

func (s *stubZaloFriendVerifier) IsVerifiedZaloCustomer(context.Context, uuid.UUID, string) (bool, error) {
	s.calls.Add(1)
	return s.verified, s.err
}

func newFriendRequestTestChannel(t *testing.T, verifier *stubZaloFriendVerifier) *Channel {
	t.Helper()
	channel := newTestChannel(t, config.ZaloPersonalConfig{
		DMPolicy:                         "open",
		GroupPolicy:                      "open",
		AutoAcceptVerifiedFriendRequests: true,
	})
	channel.SetTenantID(uuid.MustParse("0193a5b0-7000-7000-8000-000000000001"))
	channel.SetName("quangia")
	channel.SetFriendRequestServices(verifier, nil)
	channel.mu.Lock()
	channel.sess = &protocol.Session{UID: "bot-uid"}
	channel.mu.Unlock()
	return channel
}

func TestHandleFriendRequestAcceptsVerifiedCustomerOnce(t *testing.T) {
	verifier := &stubZaloFriendVerifier{verified: true}
	channel := newFriendRequestTestChannel(t, verifier)
	var accepts atomic.Int32
	channel.acceptFriendRequestFn = func(context.Context, *protocol.Session, string) error {
		accepts.Add(1)
		return nil
	}
	request := protocol.FriendRequest{FromUID: "6631971342486485220", ToUID: "bot-uid"}

	channel.handleFriendRequest(context.Background(), request)
	channel.handleFriendRequest(context.Background(), request)

	if got := accepts.Load(); got != 1 {
		t.Fatalf("accept calls = %d, want 1", got)
	}
	if got := verifier.calls.Load(); got != 1 {
		t.Fatalf("verifier calls = %d, want 1", got)
	}
}

func TestHandleFriendRequestSkipsUnverifiedCustomer(t *testing.T) {
	verifier := &stubZaloFriendVerifier{verified: false}
	channel := newFriendRequestTestChannel(t, verifier)
	var accepts atomic.Int32
	channel.acceptFriendRequestFn = func(context.Context, *protocol.Session, string) error {
		accepts.Add(1)
		return nil
	}

	channel.handleFriendRequest(context.Background(), protocol.FriendRequest{FromUID: "unknown", ToUID: "bot-uid"})

	if got := accepts.Load(); got != 0 {
		t.Fatalf("accept calls = %d, want 0", got)
	}
}

func TestHandleFriendRequestFailsClosedWhenVerificationFails(t *testing.T) {
	verifier := &stubZaloFriendVerifier{verified: true, err: errors.New("database unavailable")}
	channel := newFriendRequestTestChannel(t, verifier)
	var accepts atomic.Int32
	channel.acceptFriendRequestFn = func(context.Context, *protocol.Session, string) error {
		accepts.Add(1)
		return nil
	}

	channel.handleFriendRequest(context.Background(), protocol.FriendRequest{FromUID: "6631971342486485220", ToUID: "bot-uid"})

	if got := accepts.Load(); got != 0 {
		t.Fatalf("accept calls = %d, want 0", got)
	}
}

func TestHandleFriendRequestRejectsWrongTarget(t *testing.T) {
	verifier := &stubZaloFriendVerifier{verified: true}
	channel := newFriendRequestTestChannel(t, verifier)
	var accepts atomic.Int32
	channel.acceptFriendRequestFn = func(context.Context, *protocol.Session, string) error {
		accepts.Add(1)
		return nil
	}

	channel.handleFriendRequest(context.Background(), protocol.FriendRequest{FromUID: "6631971342486485220", ToUID: "different-bot"})

	if got := accepts.Load(); got != 0 {
		t.Fatalf("accept calls = %d, want 0", got)
	}
}
