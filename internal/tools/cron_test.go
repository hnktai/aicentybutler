package tools

import (
	"context"
	"strings"
	"testing"
	"time"

	"github.com/nextlevelbuilder/goclaw/internal/store"
)

type testCronStore struct {
	jobs      map[string]*store.CronJob
	updateCnt int
	runCnt    int
	lastForce bool
	updateErr error
	runErr    error
}

func newTestCronStore(job *store.CronJob) *testCronStore {
	jobs := map[string]*store.CronJob{}
	if job != nil {
		jobs[job.ID] = job
	}
	return &testCronStore{jobs: jobs}
}

func (s *testCronStore) AddJob(_ context.Context, name string, sched store.CronSchedule, msg string, _ bool, _ string, _ string, _ string, _ string) (*store.CronJob, error) {
	j := &store.CronJob{
		ID:       "mock-job-id",
		Name:     name,
		Schedule: sched,
		Payload:  store.CronPayload{Message: msg},
	}
	s.jobs[j.ID] = j
	return j, nil
}

func (s *testCronStore) GetJob(_ context.Context, jobID string) (*store.CronJob, bool) {
	job, ok := s.jobs[jobID]
	return job, ok
}

func (s *testCronStore) ListJobs(context.Context, bool, string, string) []store.CronJob {
	var jobs []store.CronJob
	for _, job := range s.jobs {
		jobs = append(jobs, *job)
	}
	return jobs
}

func (s *testCronStore) RemoveJob(context.Context, string) error { return nil }

func (s *testCronStore) UpdateJob(_ context.Context, jobID string, patch store.CronJobPatch) (*store.CronJob, error) {
	s.updateCnt++
	if s.updateErr != nil {
		return nil, s.updateErr
	}
	job := s.jobs[jobID]
	if patch.Message != "" {
		job.Payload.Message = patch.Message
	}
	return job, nil
}

func (s *testCronStore) EnableJob(context.Context, string, bool) error { return nil }
func (s *testCronStore) GetRunLog(context.Context, string, int, int) ([]store.CronRunLogEntry, int) {
	return nil, 0
}
func (s *testCronStore) Status() map[string]any                                      { return map[string]any{} }
func (s *testCronStore) Start() error                                                { return nil }
func (s *testCronStore) Stop()                                                       {}
func (s *testCronStore) SetOnJob(func(*store.CronJob) (*store.CronJobResult, error)) {}
func (s *testCronStore) SetOnEvent(func(store.CronEvent))                            {}

func (s *testCronStore) RunJob(_ context.Context, _ string, force bool) (bool, string, error) {
	s.runCnt++
	s.lastForce = force
	return true, "", s.runErr
}

func (s *testCronStore) GetDueJobs(time.Time) []store.CronJob { return nil }
func (s *testCronStore) SetDefaultTimezone(string)            {}

func TestCronToolBlocksCredentialBoundUpdateByDifferentUser(t *testing.T) {
	cronStore := newTestCronStore(&store.CronJob{
		ID:     "job-1",
		UserID: "group:telegram:-100123",
		Payload: store.CronPayload{
			Message:          "old",
			CredentialUserID: "tenant-user-a",
		},
	})
	tool := NewCronTool(cronStore)
	ctx := store.WithUserID(context.Background(), "group:telegram:-100123")
	ctx = store.WithCredentialUserID(ctx, "tenant-user-b")

	result := tool.Execute(ctx, map[string]any{
		"action": "update",
		"jobId":  "job-1",
		"patch":  map[string]any{"message": "run gh issue list"},
	})

	if !result.IsError || !strings.Contains(result.ForLLM, "credential context") {
		t.Fatalf("expected credential context error, got %#v", result)
	}
	if cronStore.updateCnt != 0 {
		t.Fatalf("UpdateJob called %d times, want 0", cronStore.updateCnt)
	}
}

func TestCronToolListRedactsCredentialUserID(t *testing.T) {
	cronStore := newTestCronStore(&store.CronJob{
		ID:     "job-1",
		UserID: "group:telegram:-100123",
		Payload: store.CronPayload{
			Message:          "run gh issue list",
			CredentialUserID: "tenant-user-a",
		},
	})
	tool := NewCronTool(cronStore)
	ctx := store.WithUserID(context.Background(), "group:telegram:-100123")

	result := tool.Execute(ctx, map[string]any{"action": "list", "includeDisabled": true})

	if result.IsError {
		t.Fatalf("list returned error: %#v", result)
	}
	if strings.Contains(result.ForLLM, "tenant-user-a") || strings.Contains(result.ForLLM, "credentialUserId") {
		t.Fatalf("credential identity leaked in list response: %s", result.ForLLM)
	}
}

func TestCronToolBlocksCredentialBoundRunByDifferentUser(t *testing.T) {
	cronStore := newTestCronStore(&store.CronJob{
		ID:     "job-1",
		UserID: "group:telegram:-100123",
		Payload: store.CronPayload{
			Message:          "run gh issue list",
			CredentialUserID: "tenant-user-a",
		},
	})
	tool := NewCronTool(cronStore)
	ctx := store.WithUserID(context.Background(), "group:telegram:-100123")
	ctx = store.WithCredentialUserID(ctx, "tenant-user-b")

	result := tool.Execute(ctx, map[string]any{
		"action":  "run",
		"jobId":   "job-1",
		"runMode": "force",
	})

	if !result.IsError || !strings.Contains(result.ForLLM, "credential context") {
		t.Fatalf("expected credential context error, got %#v", result)
	}
	if cronStore.runCnt != 0 {
		t.Fatalf("RunJob called %d times, want 0", cronStore.runCnt)
	}
}

// A command payload must not be slipped in via update when command cron is
// disabled — the disabled-gateway contract is that command jobs cannot be
// created OR mutated into existence.
func TestCronToolUpdateBlocksCommandWhenDisabled(t *testing.T) {
	cronStore := newTestCronStore(&store.CronJob{ID: "job-1", Payload: store.CronPayload{Message: "old"}})
	tool := NewCronTool(cronStore) // commandEnabled defaults to false

	result := tool.Execute(context.Background(), map[string]any{
		"action": "update",
		"jobId":  "job-1",
		"patch":  map[string]any{"commandArgv": []any{"echo", "hi"}},
	})

	if !result.IsError || !strings.Contains(result.ForLLM, "disabled") {
		t.Fatalf("expected command-disabled error, got %#v", result)
	}
	if cronStore.updateCnt != 0 {
		t.Fatalf("UpdateJob called %d times, want 0", cronStore.updateCnt)
	}
}

// Update must validate the command spec; an invalid argv must be rejected even
// when command cron is enabled.
func TestCronToolUpdateRejectsInvalidCommandSpec(t *testing.T) {
	cronStore := newTestCronStore(&store.CronJob{ID: "job-1", Payload: store.CronPayload{Message: "old"}})
	tool := NewCronTool(cronStore)
	tool.SetCommandEnabled(true)

	result := tool.Execute(context.Background(), map[string]any{
		"action": "update",
		"jobId":  "job-1",
		"patch":  map[string]any{"commandArgv": []any{""}}, // argv[0] empty → invalid
	})

	if !result.IsError {
		t.Fatalf("expected invalid command spec error, got %#v", result)
	}
	if cronStore.updateCnt != 0 {
		t.Fatalf("UpdateJob called %d times, want 0", cronStore.updateCnt)
	}
}

func TestParseAtSchedule_Formats(t *testing.T) {
	loc := time.FixedZone("Asia/Ho_Chi_Minh", 7*3600)
	nowVN := time.Now().In(loc)

	// 1. Time in future (Vietnam timezone)
	futureTime := nowVN.Add(2 * time.Hour).Format("2006-01-02 15:04:05")
	ms, err := parseAtSchedule(map[string]any{
		"time": futureTime,
		"tz":   "Asia/Ho_Chi_Minh",
	})
	if err != nil {
		t.Fatalf("unexpected error for future time: %v", err)
	}
	if ms == nil || *ms <= time.Now().UnixMilli() {
		t.Fatalf("expected future ms timestamp, got %v", ms)
	}

	// 2. Format HH:mm
	futureHHMM := nowVN.Add(10 * time.Minute).Format("15:04")
	ms2, err2 := parseAtSchedule(map[string]any{
		"time": futureHHMM,
		"tz":   "Asia/Ho_Chi_Minh",
	})
	if err2 != nil {
		t.Fatalf("unexpected error for HH:mm: %v", err2)
	}
	if ms2 == nil || *ms2 <= time.Now().UnixMilli() {
		t.Fatalf("expected future ms timestamp for HH:mm, got %v", ms2)
	}

	// 3. Past time should error
	pastTime := "2020-01-01 10:00:00"
	_, err3 := parseAtSchedule(map[string]any{
		"time": pastTime,
		"tz":   "Asia/Ho_Chi_Minh",
	})
	if err3 == nil {
		t.Fatal("expected error for past time, got nil")
	}

	// 4. Invalid time string should error
	_, err4 := parseAtSchedule(map[string]any{
		"time": "not-a-time",
	})
	if err4 == nil {
		t.Fatal("expected error for invalid time string, got nil")
	}

	// 5. Direct atMs numeric in future should pass
	futureMs := float64(time.Now().Add(1 * time.Hour).UnixMilli())
	ms5, err5 := parseAtSchedule(map[string]any{
		"atMs": futureMs,
	})
	if err5 != nil {
		t.Fatalf("unexpected error for atMs: %v", err5)
	}
	if *ms5 != int64(futureMs) {
		t.Fatalf("expected %d, got %d", int64(futureMs), *ms5)
	}
}

func TestParseAtSchedule_RelativeDelay(t *testing.T) {
	nowMs := time.Now().UnixMilli()

	cases := []struct {
		name     string
		input    map[string]any
		expected time.Duration
	}{
		{
			name:     "numeric in_minutes",
			input:    map[string]any{"in_minutes": 5},
			expected: 5 * time.Minute,
		},
		{
			name:     "string delay 5m",
			input:    map[string]any{"delay": "5m"},
			expected: 5 * time.Minute,
		},
		{
			name:     "string delay 5p",
			input:    map[string]any{"delay": "5p"},
			expected: 5 * time.Minute,
		},
		{
			name:     "string delay 5 phút",
			input:    map[string]any{"delay": "5 phút"},
			expected: 5 * time.Minute,
		},
		{
			name:     "string delay 5p nữa (Vietnamese suffix)",
			input:    map[string]any{"delay": "5p nữa"},
			expected: 5 * time.Minute,
		},
		{
			name:     "string delay 5 phút nữa (Vietnamese suffix)",
			input:    map[string]any{"delay": "5 phút nữa"},
			expected: 5 * time.Minute,
		},
		{
			name:     "string delay trong 10 phút (Vietnamese prefix)",
			input:    map[string]any{"delay": "trong 10 phút"},
			expected: 10 * time.Minute,
		},
		{
			name:     "string delay sau 1 tiếng (Vietnamese prefix & unit)",
			input:    map[string]any{"delay": "sau 1 tiếng"},
			expected: 1 * time.Hour,
		},
		{
			name:     "string delay 10s",
			input:    map[string]any{"delay": "10s"},
			expected: 10 * time.Second,
		},
		{
			name:     "string delay 1h",
			input:    map[string]any{"delay": "1h"},
			expected: 1 * time.Hour,
		},
		{
			name:     "numeric in_seconds",
			input:    map[string]any{"in_seconds": 30},
			expected: 30 * time.Second,
		},
		{
			name:     "numeric in_hours",
			input:    map[string]any{"in_hours": 2},
			expected: 2 * time.Hour,
		},
		{
			name:     "time field mistakenly given relative string 5p",
			input:    map[string]any{"time": "5p"},
			expected: 5 * time.Minute,
		},
	}

	for _, tc := range cases {
		t.Run(tc.name, func(t *testing.T) {
			ms, err := parseAtSchedule(tc.input)
			if err != nil {
				t.Fatalf("unexpected error for %s: %v", tc.name, err)
			}
			if ms == nil {
				t.Fatalf("expected non-nil ms for %s", tc.name)
			}
			diff := *ms - nowMs
			expectedMs := tc.expected.Milliseconds()
			// allow up to 3000ms delta for test execution
			if diff < expectedMs-3000 || diff > expectedMs+3000 {
				t.Fatalf("%s: expected diff ~%d ms, got %d ms", tc.name, expectedMs, diff)
			}
		})
	}
}

func TestCronToolAdd_RelativeDelay(t *testing.T) {
	cronStore := newTestCronStore(nil)
	tool := NewCronTool(cronStore)

	// Test 1: in_minutes at job root level
	res := tool.handleAdd(context.Background(), map[string]any{
		"action": "add",
		"job": map[string]any{
			"name":       "remind-study-1",
			"message":    "Ôn bài thôi con ơi!",
			"in_minutes": 5,
		},
	}, "agent-1", "user-1")

	if res.IsError {
		t.Fatalf("handleAdd failed with in_minutes at root: %s", res.ForLLM)
	}

	// Test 2: delay "5m" inside schedule
	res2 := tool.handleAdd(context.Background(), map[string]any{
		"action": "add",
		"job": map[string]any{
			"name":    "remind-study-2",
			"message": "Ôn bài thôi con ơi!",
			"schedule": map[string]any{
				"delay": "5p",
			},
		},
	}, "agent-1", "user-1")

	if res2.IsError {
		t.Fatalf("handleAdd failed with delay 5p in schedule: %s", res2.ForLLM)
	}
}

func TestCronToolUpdate_RelativeDelay(t *testing.T) {
	cronStore := newTestCronStore(&store.CronJob{
		ID:       "job-1",
		AgentID:  "agent-1",
		UserID:   "user-1",
		Name:     "remind-old",
		Schedule: store.CronSchedule{Kind: "at"},
		Payload:  store.CronPayload{Message: "old message"},
	})
	tool := NewCronTool(cronStore)

	// Test update with in_minutes at patch root
	res := tool.handleUpdate(context.Background(), map[string]any{
		"action": "update",
		"jobId":  "job-1",
		"patch": map[string]any{
			"in_minutes": 10,
		},
	}, "agent-1", "user-1")

	if res.IsError {
		t.Fatalf("handleUpdate failed with in_minutes: %s", res.ForLLM)
	}

	// Test update with delay in patch.schedule
	res2 := tool.handleUpdate(context.Background(), map[string]any{
		"action": "update",
		"jobId":  "job-1",
		"patch": map[string]any{
			"schedule": map[string]any{
				"delay": "15p nữa",
			},
		},
	}, "agent-1", "user-1")

	if res2.IsError {
		t.Fatalf("handleUpdate failed with delay: %s", res2.ForLLM)
	}
}
