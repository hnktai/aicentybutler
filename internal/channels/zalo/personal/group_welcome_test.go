package personal

import (
	"strings"
	"testing"

	"github.com/nextlevelbuilder/goclaw/internal/channels"
	"github.com/nextlevelbuilder/goclaw/internal/config"
)

func TestFirstGroupWelcomeMessageHasPriorityGuidance(t *testing.T) {
	for _, expected := range []string{
		"@Quản Gia",
		"nhắc lịch",
		"nhắn riêng Quản Gia",
		"Thầy/Cô đúng môn kèm riêng",
	} {
		if !strings.Contains(firstGroupWelcomeMessage, expected) {
			t.Fatalf("welcome missing %q: %q", expected, firstGroupWelcomeMessage)
		}
	}
}

func TestClaimFirstGroupWelcomeOnlyOncePerProcess(t *testing.T) {
	channel := newTestChannel(t, config.ZaloPersonalConfig{
		DMPolicy:    "open",
		GroupPolicy: "open",
	})
	channel.SetGroupHistory(channels.NewPendingHistory())

	if !channel.claimFirstGroupWelcome("group-1") {
		t.Fatal("first group message should claim welcome")
	}
	if channel.claimFirstGroupWelcome("group-1") {
		t.Fatal("second group message should not claim welcome")
	}
}
