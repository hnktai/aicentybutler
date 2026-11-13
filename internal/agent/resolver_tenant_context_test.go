package agent

import (
	"context"
	"testing"

	"github.com/google/uuid"

	"github.com/nextlevelbuilder/goclaw/internal/providers"
	"github.com/nextlevelbuilder/goclaw/internal/store"
)

type resolverTenantAgentStore struct {
	store.AgentStore
	agent       *store.AgentData
	gotTenantID uuid.UUID
}

func (s *resolverTenantAgentStore) GetByKey(context.Context, string) (*store.AgentData, error) {
	return s.agent, nil
}

func (s *resolverTenantAgentStore) GetByID(context.Context, uuid.UUID) (*store.AgentData, error) {
	return s.agent, nil
}

func (s *resolverTenantAgentStore) GetAgentContextFiles(ctx context.Context, _ uuid.UUID) ([]store.AgentContextFileData, error) {
	s.gotTenantID = store.TenantIDFromContext(ctx)
	return nil, nil
}

func TestManagedResolverScopesStoreReadsToAgentTenant(t *testing.T) {
	tenantID := uuid.Must(uuid.NewV7())
	agentStore := &resolverTenantAgentStore{agent: &store.AgentData{
		BaseModel: store.BaseModel{ID: uuid.Must(uuid.NewV7())},
		TenantID:  tenantID,
		AgentKey:  "family-butler",
		AgentType: store.AgentTypePredefined,
		Provider:  "stub",
		Status:    store.AgentStatusActive,
	}}
	providerRegistry := providers.NewRegistry(store.TenantIDFromContext)
	providerRegistry.RegisterForTenant(tenantID, &stubProvider{})

	resolver := NewManagedResolver(ResolverDeps{
		AgentStore:  agentStore,
		ProviderReg: providerRegistry,
	})
	if _, err := resolver(context.Background(), "family-butler"); err != nil {
		t.Fatalf("resolve agent: %v", err)
	}
	if agentStore.gotTenantID != tenantID {
		t.Fatalf("context tenant = %v, want %v", agentStore.gotTenantID, tenantID)
	}
}
