package channels

import (
	"strings"
	"testing"
	"time"
)

func TestPendingHistoryMarkerIsDurableInMemoryAndHiddenFromPrompt(t *testing.T) {
	history := NewPendingHistory()
	if history.HasMarker("group-1", GroupWelcomeMarkerV1) {
		t.Fatal("new group unexpectedly has welcome marker")
	}
	if err := history.RecordMarker("group-1", GroupWelcomeMarkerV1); err != nil {
		t.Fatal(err)
	}
	if !history.HasMarker("group-1", GroupWelcomeMarkerV1) {
		t.Fatal("recorded marker was not found")
	}

	history.Record("group-1", HistoryEntry{
		Sender:    "Lan",
		SenderID:  "user-1",
		Body:      "Cả nhà chào Quản Gia",
		Timestamp: time.Now(),
	}, 20)
	prompt := history.BuildContext("group-1", "@Quản Gia xin chào", 20)
	if strings.Contains(prompt, GroupWelcomeMarkerV1) || strings.Contains(prompt, internalHistoryMarkerSenderID) {
		t.Fatalf("internal marker leaked into prompt: %q", prompt)
	}
	if !strings.Contains(prompt, "Cả nhà chào Quản Gia") {
		t.Fatalf("real group message missing from prompt: %q", prompt)
	}
}

func TestPendingHistoryMarkerIsIdempotent(t *testing.T) {
	history := NewPendingHistory()
	if err := history.RecordMarker("group-1", GroupWelcomeMarkerV1); err != nil {
		t.Fatal(err)
	}
	if err := history.RecordMarker("group-1", GroupWelcomeMarkerV1); err != nil {
		t.Fatal(err)
	}
	entries := history.GetEntries("group-1")
	if len(entries) != 1 {
		t.Fatalf("marker entries = %d, want 1", len(entries))
	}
}
