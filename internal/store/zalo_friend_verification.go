package store

import (
	"context"

	"github.com/google/uuid"
)

// ZaloFriendVerificationStore verifies that a Zalo UID belongs to an active,
// provisioned Butler customer before the personal account accepts a request.
type ZaloFriendVerificationStore interface {
	IsVerifiedZaloCustomer(ctx context.Context, channelTenantID uuid.UUID, zaloUID string) (bool, error)
}
