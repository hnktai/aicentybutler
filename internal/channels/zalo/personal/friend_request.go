package personal

import (
	"context"
	"encoding/json"
	"errors"
	"log/slog"
	"strings"
	"time"

	"github.com/nextlevelbuilder/goclaw/internal/channels/zalo/personal/protocol"
	"github.com/nextlevelbuilder/goclaw/internal/store"
)

const (
	friendRequestActionTimeout = 15 * time.Second
	friendRequestAuditTimeout  = 3 * time.Second
	friendRequestDedupWindow   = 5 * time.Minute
)

func (c *Channel) dispatchFriendRequest(ctx context.Context, request protocol.FriendRequest) {
	select {
	case c.friendRequestSlots <- struct{}{}:
		go func() {
			defer func() { <-c.friendRequestSlots }()
			c.handleFriendRequest(ctx, request)
		}()
	default:
		uid := strings.TrimSpace(request.FromUID)
		slog.Warn("security.zalo_friend_request_worker_saturated", "channel", c.Name(), "user_id", uid)
		c.auditFriendRequest(uid, "zalo.friend_request.worker_saturated", nil)
	}
}

func (c *Channel) claimFriendRequest(uid string) bool {
	now := time.Now()
	for {
		actual, loaded := c.friendRequestClaims.LoadOrStore(uid, now)
		if !loaded {
			return true
		}
		claimedAt, ok := actual.(time.Time)
		if !ok || now.Sub(claimedAt) >= friendRequestDedupWindow {
			if c.friendRequestClaims.CompareAndSwap(uid, actual, now) {
				return true
			}
			continue
		}
		return false
	}
}

func (c *Channel) handleFriendRequest(parent context.Context, request protocol.FriendRequest) {
	if !c.config.AutoAcceptVerifiedFriendRequests {
		return
	}
	uid := strings.TrimSpace(request.FromUID)
	if uid == "" {
		slog.Warn("security.zalo_friend_request_invalid", "channel", c.Name(), "reason", "missing_from_uid")
		return
	}
	if !c.claimFriendRequest(uid) {
		slog.Debug("zalo_personal.friend_request_duplicate", "channel", c.Name(), "user_id", uid)
		return
	}
	keepClaim := false
	defer func() {
		if !keepClaim {
			c.friendRequestClaims.Delete(uid)
		}
	}()

	ctx, cancel := context.WithTimeout(parent, friendRequestActionTimeout)
	stopWatchDone := make(chan struct{})
	go func() {
		select {
		case <-c.stopCh:
			cancel()
		case <-stopWatchDone:
		}
	}()
	defer func() {
		close(stopWatchDone)
		cancel()
	}()
	ctx = store.WithTenantID(ctx, c.TenantID())

	if c.friendVerifier == nil {
		slog.Warn("security.zalo_friend_request_verifier_unavailable", "channel", c.Name(), "user_id", uid)
		c.auditFriendRequest(uid, "zalo.friend_request.verifier_unavailable", nil)
		return
	}
	verified, err := c.friendVerifier.IsVerifiedZaloCustomer(ctx, c.TenantID(), uid)
	if err != nil {
		slog.Warn("security.zalo_friend_request_verification_failed", "channel", c.Name(), "user_id", uid, "error", err)
		c.auditFriendRequest(uid, "zalo.friend_request.verification_failed", err)
		return
	}
	if !verified {
		slog.Info("zalo_personal.friend_request_skipped_unverified", "channel", c.Name(), "user_id", uid)
		c.auditFriendRequest(uid, "zalo.friend_request.skipped_unverified", nil)
		return
	}

	sess := c.session()
	if sess == nil {
		err := errors.New("Zalo session unavailable")
		slog.Warn("zalo_personal.friend_request_accept_failed", "channel", c.Name(), "user_id", uid, "error", "session unavailable")
		c.auditFriendRequest(uid, "zalo.friend_request.accept_failed", err)
		return
	}
	if request.ToUID != "" && request.ToUID != sess.UID {
		slog.Warn("security.zalo_friend_request_wrong_target", "channel", c.Name(), "user_id", uid)
		c.auditFriendRequest(uid, "zalo.friend_request.wrong_target", nil)
		return
	}
	if err := c.acceptFriendRequestFn(ctx, sess, uid); err != nil {
		slog.Warn("zalo_personal.friend_request_accept_failed", "channel", c.Name(), "user_id", uid, "error", err)
		c.auditFriendRequest(uid, "zalo.friend_request.accept_failed", err)
		return
	}
	keepClaim = true
	slog.Info("zalo_personal.friend_request_accepted", "channel", c.Name(), "user_id", uid)
	c.auditFriendRequest(uid, "zalo.friend_request.accepted", nil)
}

func (c *Channel) auditFriendRequest(uid, action string, actionErr error) {
	if c.activityStore == nil {
		return
	}
	ctx, cancel := context.WithTimeout(context.Background(), friendRequestAuditTimeout)
	defer cancel()
	ctx = store.WithTenantID(ctx, c.TenantID())
	details := map[string]any{"channel": c.Name()}
	if actionErr != nil {
		details["error"] = actionErr.Error()
	}
	raw, err := json.Marshal(details)
	if err != nil {
		slog.Warn("zalo_personal.friend_request_audit_marshal_failed", "channel", c.Name(), "error", err)
		return
	}
	if err := c.activityStore.Log(ctx, &store.ActivityLog{
		ActorType:  "channel",
		ActorID:    c.Name(),
		Action:     action,
		EntityType: "zalo_user",
		EntityID:   uid,
		Details:    raw,
	}); err != nil {
		slog.Warn("zalo_personal.friend_request_audit_failed", "channel", c.Name(), "user_id", uid, "error", err)
	}
}
