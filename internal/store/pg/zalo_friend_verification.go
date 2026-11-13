package pg

import (
	"context"
	"database/sql"
	"fmt"
	"strings"

	"github.com/google/uuid"

	"github.com/nextlevelbuilder/goclaw/internal/store"
)

// PGZaloFriendVerificationStore validates Zalo identities against Butler's
// active identity registry and its owning handover/family-member record.
type PGZaloFriendVerificationStore struct {
	db *sql.DB
}

func NewPGZaloFriendVerificationStore(db *sql.DB) *PGZaloFriendVerificationStore {
	return &PGZaloFriendVerificationStore{db: db}
}

func (s *PGZaloFriendVerificationStore) IsVerifiedZaloCustomer(
	ctx context.Context,
	channelTenantID uuid.UUID,
	zaloUID string,
) (bool, error) {
	zaloUID = strings.TrimSpace(zaloUID)
	if zaloUID == "" || channelTenantID == uuid.Nil {
		return false, nil
	}

	var verified bool
	err := s.db.QueryRowContext(ctx, `
		SELECT EXISTS (
			SELECT 1
			FROM butler_identity_bindings b
			JOIN quangia_pending_handover h ON h.id = b.handover_id
			WHERE b.external_user_id = $1
			  AND b.status = 'active'
			  AND ($2 = $3 OR b.tenant_id = $2)
			  AND (
				(b.external_user_id = h.claimed_zalo_personal_id AND h.claimed_at IS NOT NULL)
				OR EXISTS (
					SELECT 1 FROM quangia_family_members m
					WHERE m.handover_id = b.handover_id
					  AND m.zalo_uid = b.external_user_id
				)
			  )
		)`, zaloUID, channelTenantID, store.MasterTenantID).Scan(&verified)
	if err != nil {
		return false, fmt.Errorf("verify Zalo customer: %w", err)
	}
	return verified, nil
}
