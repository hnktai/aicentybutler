package tools

import (
	"context"
	"encoding/json"
	"fmt"
	"regexp"
	"strconv"
	"strings"
	"time"

	"github.com/google/uuid"
	"github.com/nextlevelbuilder/goclaw/internal/store"
)

// CronTool lets agents manage Gateway cron jobs.
// Matching OpenClaw src/agents/tools/cron-tool.ts.
type CronTool struct {
	cronStore      store.CronStore
	permStore      store.ConfigPermissionStore // nil = no group restriction
	providerStore  store.ProviderStore         // nil = provider override by name unavailable
	commandEnabled bool                        // allow deterministic command payloads (mirrors cron.command_enabled)
}

func NewCronTool(cronStore store.CronStore) *CronTool {
	return &CronTool{cronStore: cronStore}
}

// SetConfigPermStore enables group cron mutation restriction.
func (t *CronTool) SetConfigPermStore(s store.ConfigPermissionStore) {
	t.permStore = s
}

// SetProviderStore enables resolving per-job LLM provider overrides by name.
func (t *CronTool) SetProviderStore(s store.ProviderStore) {
	t.providerStore = s
}

// SetCommandEnabled allows this tool to create deterministic command-payload
// cron jobs (kind="command", no LLM). Mirrors the gateway's cron.command_enabled.
func (t *CronTool) SetCommandEnabled(enabled bool) {
	t.commandEnabled = enabled
}

func (t *CronTool) Name() string { return "cron" }

func (t *CronTool) Description() string {
	return `Manage Gateway cron jobs.
Always send a JSON object with an "action" field.

VALID ACTIONS AND EXACT PAYLOAD SHAPES:
1) status
{ "action": "status" }

2) list
{ "action": "list", "includeDisabled": true|false }

3) add
{
  "action": "add",
  "job": {
    "name": "string",             // required, lowercase slug: [a-z0-9-]+
    "schedule": { ... },          // required
    "message": "string",          // required
    "deliver": true|false,        // optional, default false
    "channel": "string",          // optional, auto-filled from current channel context
    "to": "string",               // optional
    "agentId": "string",          // optional, defaults to current agent
    "provider": "string",         // optional, LLM provider NAME (e.g. "groq") to run this job on a cheaper model; unset → agent default
    "model": "string",            // optional, model id for the override provider
    "deleteAfterRun": true|false  // optional, default true for schedule.kind="at"
  }
}

4) update
{
  "action": "update",
  "jobId": "string",
  "patch": {
    "name": "string",
    "schedule": { ... },
    "message": "string",
    "deliver": true|false,
    "channel": "string",
    "to": "string",
    "agentId": "string",
    "provider": "string",
    "model": "string",
    "deleteAfterRun": true|false,
    "disabled": true|false
  }
}

5) remove
{ "action": "remove", "jobId": "string" }

6) run
{ "action": "run", "jobId": "string" }

7) runs
{ "action": "runs", "jobId": "string" }

SCHEDULE SCHEMA:
- at: { "kind": "at", "in_minutes": 5 } OR { "kind": "at", "delay": "5m" } OR { "kind": "at", "time": "YYYY-MM-DD HH:mm:ss", "tz": "Asia/Ho_Chi_Minh", "expectedWeekday": 1..7 }
  * RELATIVE TIME (RECOMMENDED for reminders/delays): Use "in_minutes": 5 or "delay": "5m" (supports "5p", "5 phút", "1h", "30s"). No need to query datetime!
  * ABSOLUTE TIME: Use "time" string (e.g. "2026-11-10 07:00:00") with "tz". Or "time": "HH:mm" for today/tomorrow.
  * WEEKDAY SAFETY: If the user names a weekday, always set "expectedWeekday" to its ISO value (Monday=1 ... Sunday=7). The backend rejects a date/weekday mismatch.
  * Fallback: { "kind": "at", "atMs": <unix-milliseconds> }
- every: { "kind": "every", "everyMs": <interval-ms> }
- cron: { "kind": "cron", "expr": "<5-field cron>", "tz": "<IANA timezone, e.g. Asia/Ho_Chi_Minh; omit for gateway default>" }

RULES:
- For action="add", send the job inside "job". Do not place job fields at the root level.
- For action="update", send changes inside "patch". Do not place patch fields at the root level.
- Always use "jobId". Do not use "id".
- "name", "schedule", and "message" are required for add.
- "name" must match: lowercase letters, numbers, hyphens only.
- Before creating or updating a scheduled job, call the datetime tool first to get the precise current time and unix_ms timestamp. Never guess timestamps.
- The cron result's "scheduledLocal" fields are authoritative. Use its date, time, and weekday verbatim in the confirmation; never recalculate them.
- Omit optional fields when unknown; do not invent placeholder values like "", 0, or null unless required.
- Jobs run as isolated agent turns using the provided "message".

DETERMINISTIC COMMAND JOBS (no LLM, zero tokens):
- Instead of "message", set "command" to a shell string (run as sh -c) OR
  "commandArgv" to an explicit argv array (no shell parsing).
- Optional: "commandCwd", "commandEnv" {"KEY":"VAL"}, "commandTimeoutSeconds".
- The command runs inside the gateway process. Only available when the gateway
  has cron.command_enabled=true; otherwise add returns an error.
- Use for scheduled probes/scripts that don't need the model. Output is delivered
  like a normal job when "deliver" is set; a non-zero exit records the run as an error.`
}

func (t *CronTool) Parameters() map[string]any {
	return map[string]any{
		"type": "object",
		"properties": map[string]any{
			"action": map[string]any{
				"type":        "string",
				"description": "The cron action to perform",
				"enum":        []string{"status", "list", "add", "update", "remove", "run", "runs"},
			},
			"includeDisabled": map[string]any{
				"type":        "boolean",
				"description": "Include disabled jobs in list (default false)",
			},
			"job": map[string]any{
				"type":                 "object",
				"description":          "Job definition for add action (name, schedule, message, deliver, channel, to, agentId, provider, model, deleteAfterRun)",
				"additionalProperties": true,
			},
			"jobId": map[string]any{
				"type":        "string",
				"description": "Job ID for update/remove/run/runs actions",
			},
			"id": map[string]any{
				"type":        "string",
				"description": "Backward compatibility alias for jobId",
			},
			"patch": map[string]any{
				"type":                 "object",
				"description":          "Patch object for update action",
				"additionalProperties": true,
			},
			"runMode": map[string]any{
				"type":        "string",
				"description": "Run mode: 'due' (only if due) or 'force' (immediate)",
				"enum":        []string{"due", "force"},
			},
		},
		"required": []string{"action"},
	}
}

func (t *CronTool) Execute(ctx context.Context, args map[string]any) *Result {
	action, _ := args["action"].(string)
	if action == "" {
		return ErrorResult("action parameter is required")
	}

	// Group cron permission check for mutation actions
	if t.permStore != nil && (action == "add" || action == "update" || action == "remove") {
		if err := store.CheckCronPermission(ctx, t.permStore); err != nil {
			return ErrorResult("permission denied: only users with cron or file_writer permission can manage cron jobs in group chats")
		}
	}

	agentID := resolveAgentIDString(ctx)
	// SCOPE-intentional (#915 audit 2026-04-16): cron jobs follow the per-group
	// memory model — all group members share the same cron_jobs rows via the
	// group-scope user_id. Migrating to ActorIDFromContext would split jobs
	// per-individual and break collaborative /cron list/add/remove UX.
	userID := store.UserIDFromContext(ctx)

	switch action {
	case "status":
		return t.handleStatus()
	case "list":
		return t.handleList(ctx, args, agentID, userID)
	case "add":
		return t.handleAdd(ctx, args, agentID, userID)
	case "update":
		return t.handleUpdate(ctx, args, agentID, userID)
	case "remove":
		return t.handleRemove(ctx, args, agentID, userID)
	case "run":
		return t.handleRun(ctx, args, agentID, userID)
	case "runs":
		return t.handleRuns(ctx, args, agentID, userID)
	default:
		return ErrorResult(fmt.Sprintf("unknown action: %s", action))
	}
}

func (t *CronTool) handleStatus() *Result {
	status := t.cronStore.Status()
	data, _ := json.MarshalIndent(status, "", "  ")
	return NewResult(string(data))
}

func (t *CronTool) handleList(ctx context.Context, args map[string]any, agentID, userID string) *Result {
	includeDisabled, _ := args["includeDisabled"].(bool)
	jobs := t.cronStore.ListJobs(ctx, includeDisabled, agentID, userID)
	jobs = store.RedactCronJobsCredentialContext(jobs)

	result := map[string]any{
		"jobs":  jobs,
		"count": len(jobs),
	}
	data, _ := json.MarshalIndent(result, "", "  ")
	return NewResult(string(data))
}

// parseDurationString parses standard and colloquial/Vietnamese duration strings.
// Supports: "5m", "5min", "5p", "5 phút", "30s", "30 giây", "1h", "1 giờ", "1 tiếng", etc.
func parseDurationString(raw string) (time.Duration, bool) {
	s := strings.TrimSpace(strings.ToLower(raw))
	if s == "" {
		return 0, false
	}
	// Try standard Go time.ParseDuration first (e.g. "5m", "10s", "1h30m")
	if d, err := time.ParseDuration(s); err == nil && d > 0 {
		return d, true
	}

	// Clean common prefixes ("in ", "sau ", "trong ")
	s = strings.TrimPrefix(s, "in ")
	s = strings.TrimPrefix(s, "sau ")
	s = strings.TrimPrefix(s, "trong ")
	// Clean common Vietnamese suffixes (" nữa", " toi", " tới", " sau")
	s = strings.TrimSuffix(s, " nữa")
	s = strings.TrimSuffix(s, " toi")
	s = strings.TrimSuffix(s, " tới")
	s = strings.TrimSuffix(s, " sau")
	s = strings.TrimSpace(s)
	if d, err := time.ParseDuration(s); err == nil && d > 0 {
		return d, true
	}

	// Minutes: 5m, 5p, 5phut, 5 phút, 5 min, 5 mins, 5 minutes
	reMin := regexp.MustCompile(`^(\d+(?:\.\d+)?)\s*(p|phut|phút|min|mins|minutes|m)$`)
	if m := reMin.FindStringSubmatch(s); len(m) == 3 {
		if v, err := strconv.ParseFloat(m[1], 64); err == nil && v > 0 {
			return time.Duration(v * float64(time.Minute)), true
		}
	}

	// Seconds: 30s, 30 sec, 30 giay, 30 giây
	reSec := regexp.MustCompile(`^(\d+(?:\.\d+)?)\s*(s|sec|secs|seconds|giay|giây)$`)
	if m := reSec.FindStringSubmatch(s); len(m) == 3 {
		if v, err := strconv.ParseFloat(m[1], 64); err == nil && v > 0 {
			return time.Duration(v * float64(time.Second)), true
		}
	}

	// Hours: 1h, 1 hr, 1 hour, 1 gio, 1 giờ, 1 tieng, 1 tiếng
	reHr := regexp.MustCompile(`^(\d+(?:\.\d+)?)\s*(h|hr|hrs|hour|hours|gio|giờ|tieng|tiếng)$`)
	if m := reHr.FindStringSubmatch(s); len(m) == 3 {
		if v, err := strconv.ParseFloat(m[1], 64); err == nil && v > 0 {
			return time.Duration(v * float64(time.Hour)), true
		}
	}

	// Days: 1d, 1 day, 1 ngay, 1 ngày
	reDay := regexp.MustCompile(`^(\d+(?:\.\d+)?)\s*(d|day|days|ngay|ngày)$`)
	if m := reDay.FindStringSubmatch(s); len(m) == 3 {
		if v, err := strconv.ParseFloat(m[1], 64); err == nil && v > 0 {
			return time.Duration(v * 24 * float64(time.Hour)), true
		}
	}

	return 0, false
}

// parseRelativeDuration extracts relative duration from numeric fields or string delay fields.
func parseRelativeDuration(scheduleObj map[string]any) (time.Duration, bool) {
	// Numeric minute fields
	for _, k := range []string{"in_minutes", "inMinutes", "in_mins", "minutes"} {
		if v, ok := numberFromMap(scheduleObj, k); ok && v > 0 {
			return time.Duration(v * float64(time.Minute)), true
		}
	}
	// Numeric second fields
	for _, k := range []string{"in_seconds", "inSeconds", "seconds"} {
		if v, ok := numberFromMap(scheduleObj, k); ok && v > 0 {
			return time.Duration(v * float64(time.Second)), true
		}
	}
	// Numeric hour fields
	for _, k := range []string{"in_hours", "inHours", "hours"} {
		if v, ok := numberFromMap(scheduleObj, k); ok && v > 0 {
			return time.Duration(v * float64(time.Hour)), true
		}
	}

	// String delay fields
	for _, k := range []string{"delay", "in", "after"} {
		if s, ok := scheduleObj[k].(string); ok && strings.TrimSpace(s) != "" {
			if d, ok := parseDurationString(s); ok {
				return d, true
			}
		}
	}

	// Also check if "time" or "at" was mistakenly given a relative expression like "5m", "5p", "in 5 minutes"
	for _, k := range []string{"time", "at"} {
		if s, ok := scheduleObj[k].(string); ok && strings.TrimSpace(s) != "" {
			if d, ok := parseDurationString(s); ok {
				return d, true
			}
		}
	}

	return 0, false
}

type scheduledLocalCalendar struct {
	Date       string `json:"date"`
	Time       string `json:"time"`
	DateTime   string `json:"dateTime"`
	Weekday    string `json:"weekday"`
	ISOWeekday int    `json:"isoWeekday"`
	Timezone   string `json:"timezone"`
}

var weekdayMentionPatterns = []struct {
	iso int
	re  *regexp.Regexp
}{
	{1, regexp.MustCompile(`(?i)(?:^|[\s,.;:()/_-])(?:(?:thứ|thu)\s*(?:2|hai)|monday)(?:$|[\s,.;:()/_-])`)},
	{2, regexp.MustCompile(`(?i)(?:^|[\s,.;:()/_-])(?:(?:thứ|thu)\s*(?:3|ba)|tuesday)(?:$|[\s,.;:()/_-])`)},
	{3, regexp.MustCompile(`(?i)(?:^|[\s,.;:()/_-])(?:(?:thứ|thu)\s*(?:4|tư|tu|bốn|bon)|wednesday)(?:$|[\s,.;:()/_-])`)},
	{4, regexp.MustCompile(`(?i)(?:^|[\s,.;:()/_-])(?:(?:thứ|thu)\s*(?:5|năm|nam)|thursday)(?:$|[\s,.;:()/_-])`)},
	{5, regexp.MustCompile(`(?i)(?:^|[\s,.;:()/_-])(?:(?:thứ|thu)\s*(?:6|sáu|sau)|friday)(?:$|[\s,.;:()/_-])`)},
	{6, regexp.MustCompile(`(?i)(?:^|[\s,.;:()/_-])(?:(?:thứ|thu)\s*(?:7|bảy|bay)|saturday)(?:$|[\s,.;:()/_-])`)},
	{7, regexp.MustCompile(`(?i)(?:^|[\s,.;:()/_-])(?:chủ\s*nhật|chu\s*nhat|sunday)(?:$|[\s,.;:()/_-])`)},
}

func scheduleLocation(scheduleObj map[string]any) (string, *time.Location, error) {
	tzName := stringFromMap(scheduleObj, "tz")
	if tzName == "" {
		tzName = stringFromMap(scheduleObj, "timezone")
	}
	if tzName == "" {
		tzName = "Asia/Ho_Chi_Minh"
	}

	loc, err := time.LoadLocation(tzName)
	if err != nil {
		return "", nil, fmt.Errorf("invalid timezone %q: use an IANA timezone such as Asia/Ho_Chi_Minh", tzName)
	}
	return tzName, loc, nil
}

func parseExpectedWeekday(value any) (int, bool) {
	var iso int
	switch v := value.(type) {
	case int:
		iso = v
	case int64:
		iso = int(v)
	case float64:
		if v != float64(int(v)) {
			return 0, false
		}
		iso = int(v)
	case json.Number:
		parsed, err := strconv.Atoi(v.String())
		if err != nil {
			return 0, false
		}
		iso = parsed
	case string:
		normalized := strings.TrimSpace(strings.ToLower(v))
		if parsed, err := strconv.Atoi(normalized); err == nil {
			iso = parsed
		} else {
			for _, pattern := range weekdayMentionPatterns {
				if pattern.re.MatchString(" " + normalized + " ") {
					return pattern.iso, true
				}
			}
			return 0, false
		}
	default:
		return 0, false
	}
	return iso, iso >= 1 && iso <= 7
}

func mentionedWeekdays(text string) map[int]struct{} {
	found := make(map[int]struct{})
	for _, pattern := range weekdayMentionPatterns {
		if pattern.re.MatchString(text) {
			found[pattern.iso] = struct{}{}
		}
	}
	return found
}

func validateAtScheduleWeekday(scheduleObj map[string]any, message string, atMS int64) (*scheduledLocalCalendar, error) {
	tzName, loc, err := scheduleLocation(scheduleObj)
	if err != nil {
		return nil, err
	}

	expected := mentionedWeekdays(message)
	for _, key := range []string{"expectedWeekday", "expected_weekday"} {
		value, exists := scheduleObj[key]
		if !exists {
			continue
		}
		iso, ok := parseExpectedWeekday(value)
		if !ok {
			return nil, fmt.Errorf("job.schedule.%s must be an ISO weekday from 1 (Monday) to 7 (Sunday), or a weekday name", key)
		}
		expected[iso] = struct{}{}
	}
	if len(expected) > 1 {
		return nil, fmt.Errorf("conflicting weekday references in the reminder; provide one unambiguous weekday before creating the job")
	}

	local := time.UnixMilli(atMS).In(loc)
	actualISO := isoWeekday(local)
	for wantedISO := range expected {
		if wantedISO != actualISO {
			return nil, fmt.Errorf(
				"weekday mismatch: scheduled time %s is %s (ISO %d), but the reminder says ISO weekday %d; correct the date before creating the job",
				local.Format("2006-01-02 15:04:05 MST"), local.Weekday().String(), actualISO, wantedISO,
			)
		}
	}

	return &scheduledLocalCalendar{
		Date:       local.Format("2006-01-02"),
		Time:       local.Format("15:04:05"),
		DateTime:   local.Format(time.RFC3339),
		Weekday:    local.Weekday().String(),
		ISOWeekday: actualISO,
		Timezone:   tzName,
	}, nil
}

// parseAtSchedule extracts and validates the target timestamp in milliseconds for schedule.kind="at".
// It supports relative durations (in_minutes: 5, delay: "5m"), human datetime strings, and atMs.
func parseAtSchedule(scheduleObj map[string]any) (*int64, error) {
	// 0. Relative duration support (in_minutes, delay, etc.)
	if dur, ok := parseRelativeDuration(scheduleObj); ok {
		ms := time.Now().Add(dur).UnixMilli()
		return &ms, nil
	}
	// 1. Direct atMs support (numeric)
	if v, ok := numberFromMap(scheduleObj, "atMs"); ok {
		ms := int64(v)
		if ms <= time.Now().UnixMilli() {
			return nil, fmt.Errorf("job.schedule.atMs is in the past (%d). Use a future timestamp. Current time is %d ms", ms, time.Now().UnixMilli())
		}
		return &ms, nil
	}

	// 2. String datetime support
	var rawTime string
	for _, key := range []string{"time", "datetime", "at", "targetTime"} {
		if s, ok := scheduleObj[key].(string); ok && strings.TrimSpace(s) != "" {
			rawTime = strings.TrimSpace(s)
			break
		}
	}

	if rawTime == "" {
		return nil, fmt.Errorf("job.schedule.time (e.g. 'YYYY-MM-DD HH:mm:ss' or 'HH:mm') or job.schedule.atMs is required for 'at' schedule")
	}

	// Timezone resolution (default Asia/Ho_Chi_Minh)
	_, loc, err := scheduleLocation(scheduleObj)
	if err != nil {
		return nil, err
	}

	now := time.Now().In(loc)
	var targetTime time.Time
	var parsed bool

	formats := []string{
		"2006-01-02 15:04:05",
		"2006-01-02 15:04",
		time.RFC3339,
		"2006-01-02T15:04:05",
		"2006-01-02T15:04",
		"02/01/2006 15:04:05",
		"02/01/2006 15:04",
		"15:04:05",
		"15:04",
	}

	for _, layout := range formats {
		if layout == "15:04:05" || layout == "15:04" {
			if t, err := time.ParseInLocation(layout, rawTime, loc); err == nil {
				targetTime = time.Date(now.Year(), now.Month(), now.Day(), t.Hour(), t.Minute(), t.Second(), 0, loc)
				if targetTime.Before(now) {
					targetTime = targetTime.AddDate(0, 0, 1)
				}
				parsed = true
				break
			}
		} else if strings.Contains(layout, "Z07:00") {
			if t, err := time.Parse(layout, rawTime); err == nil {
				targetTime = t.In(loc)
				parsed = true
				break
			}
		} else {
			if t, err := time.ParseInLocation(layout, rawTime, loc); err == nil {
				targetTime = t
				parsed = true
				break
			}
		}
	}

	if !parsed {
		return nil, fmt.Errorf("unable to parse scheduled time %q (supported formats: 'YYYY-MM-DD HH:mm:ss', 'YYYY-MM-DD HH:mm', 'HH:mm')", rawTime)
	}

	ms := targetTime.UnixMilli()
	if ms <= time.Now().UnixMilli() {
		return nil, fmt.Errorf("scheduled time %s (%q) is in the past. Current time is %s", targetTime.Format("2006-01-02 15:04:05 MST"), rawTime, now.Format("2006-01-02 15:04:05 MST"))
	}
	return &ms, nil
}

func (t *CronTool) handleAdd(ctx context.Context, args map[string]any, agentID, userID string) *Result {
	jobObj, ok := args["job"].(map[string]any)
	if !ok {
		return ErrorResult("job object is required for add action")
	}

	name, _ := jobObj["name"].(string)
	if name == "" {
		return ErrorResult("job.name is required")
	}

	scheduleObj, ok := jobObj["schedule"].(map[string]any)
	if !ok || scheduleObj == nil {
		scheduleObj = make(map[string]any)
	}

	// Forward relative time fields from jobObj root if specified there
	for _, k := range []string{"in_minutes", "inMinutes", "in_mins", "minutes", "delay", "in", "after", "in_seconds", "in_hours"} {
		if val, exists := jobObj[k]; exists {
			if _, inSched := scheduleObj[k]; !inSched {
				scheduleObj[k] = val
			}
		}
	}

	// Auto-infer schedule kind if missing
	if stringFromMap(scheduleObj, "kind") == "" {
		if _, hasRel := parseRelativeDuration(scheduleObj); hasRel {
			scheduleObj["kind"] = "at"
		} else if _, hasAtMs := scheduleObj["atMs"]; hasAtMs {
			scheduleObj["kind"] = "at"
		} else if _, hasTime := scheduleObj["time"]; hasTime {
			scheduleObj["kind"] = "at"
		} else if _, hasEvery := scheduleObj["everyMs"]; hasEvery {
			scheduleObj["kind"] = "every"
		} else if _, hasExpr := scheduleObj["expr"]; hasExpr {
			scheduleObj["kind"] = "cron"
		}
	}

	// Optional deterministic command payload (runs a shell command, no LLM turn).
	cmdSpec := parseCronCommandSpec(jobObj)
	if cmdSpec != nil {
		if !t.commandEnabled {
			return ErrorResult("command cron is disabled on this gateway (set cron.command_enabled=true to allow it)")
		}
		if err := store.ValidateCronCommandSpec(cmdSpec); err != nil {
			return ErrorResult(err.Error())
		}
	}

	message, _ := jobObj["message"].(string)
	if cmdSpec == nil && message == "" {
		return ErrorResult("job.message is required (or set job.command/job.commandArgv for a deterministic command job)")
	}

	// Parse schedule
	schedule := store.CronSchedule{
		Kind: stringFromMap(scheduleObj, "kind"),
	}
	var scheduledLocal *scheduledLocalCalendar
	if schedule.Kind == "" {
		return ErrorResult("job.schedule.kind is required (at, every, or cron)")
	}

	switch schedule.Kind {
	case "at":
		ms, err := parseAtSchedule(scheduleObj)
		if err != nil {
			return ErrorResult(err.Error())
		}
		scheduledLocal, err = validateAtScheduleWeekday(scheduleObj, message, *ms)
		if err != nil {
			return ErrorResult(err.Error())
		}
		schedule.AtMS = ms
		schedule.TZ = scheduledLocal.Timezone
	case "every":
		if v, ok := numberFromMap(scheduleObj, "everyMs"); ok {
			ms := int64(v)
			schedule.EveryMS = &ms
		} else {
			return ErrorResult("job.schedule.everyMs is required for 'every' schedule")
		}
	case "cron":
		schedule.Expr = stringFromMap(scheduleObj, "expr")
		if schedule.Expr == "" {
			return ErrorResult("job.schedule.expr is required for 'cron' schedule")
		}
		schedule.TZ = stringFromMap(scheduleObj, "tz")
		if schedule.TZ != "" {
			if _, err := time.LoadLocation(schedule.TZ); err != nil {
				return ErrorResult(fmt.Sprintf("invalid timezone '%s': use IANA names like 'Asia/Ho_Chi_Minh', 'America/New_York'", schedule.TZ))
			}
		}
	default:
		return ErrorResult(fmt.Sprintf("invalid schedule kind: %s (must be at, every, or cron)", schedule.Kind))
	}

	// Optional fields
	deliver, _ := jobObj["deliver"].(bool)
	channel, _ := jobObj["channel"].(string)
	to, _ := jobObj["to"].(string)

	// Auto-default deliver=true when the request comes from a real channel
	// (not CLI/system/subagent). Users chatting on Zalo/Telegram expect
	// cron results delivered back to the same chat.
	if !deliver {
		if ctxChannel := ToolChannelFromCtx(ctx); ctxChannel != "" {
			switch ctxChannel {
			case "cli", "system", "subagent", "cron", "teammate":
				// internal channels — don't auto-deliver
			default:
				deliver = true
			}
		}
	}

	// Auto-fill channel and to from context when deliver is requested.
	// Always prefer context values over LLM-provided values to prevent
	// misrouted deliveries (e.g. LLM confusing guild ID with channel ID).
	if deliver {
		if channel == "" || channel == "zalo" || channel == "zalo_personal" || channel == "zalo_oa" {
			if ctxChannel := ToolChannelFromCtx(ctx); ctxChannel != "" {
				channel = ctxChannel
			}
		}
		if to == "" {
			if ctxChatID := ToolChatIDFromCtx(ctx); ctxChatID != "" {
				to = ctxChatID
			}
		}
	}

	// Use agent ID from job object if explicitly provided, otherwise from context
	if explicit, _ := jobObj["agentId"].(string); explicit != "" {
		agentID = explicit
	}

	// Resolve optional per-job provider/model override before creating the job.
	providerID, errR := t.resolveProviderID(ctx, jobObj)
	if errR != nil {
		return errR
	}
	modelOverride, _ := jobObj["model"].(string)

	job, err := t.cronStore.AddJob(ctx, name, schedule, message, deliver, channel, to, agentID, userID)
	if err != nil {
		return ErrorResult(fmt.Sprintf("failed to create cron job: %v", err))
	}

	// Apply post-create overrides (wake_heartbeat, provider/model) in a single patch.
	overridePatch := store.CronJobPatch{}
	needOverride := false
	if wh, _ := jobObj["wake_heartbeat"].(bool); wh {
		wakeTrue := true
		overridePatch.WakeHeartbeat = &wakeTrue
		needOverride = true
	}
	if providerID != nil {
		overridePatch.ProviderID = providerID
		needOverride = true
	}
	if modelOverride != "" {
		overridePatch.Model = &modelOverride
		needOverride = true
	}
	if cmdSpec != nil {
		overridePatch.Command = cmdSpec
		needOverride = true
	}
	if needOverride {
		if updated, uErr := t.cronStore.UpdateJob(ctx, job.ID, overridePatch); uErr == nil {
			job = updated
		}
	}

	response := map[string]any{"job": store.RedactCronJobCredentialContext(*job)}
	if scheduledLocal != nil {
		response["scheduledLocal"] = scheduledLocal
	}
	data, _ := json.MarshalIndent(response, "", "  ")
	return NewResult(string(data))
}

// checkJobOwnership validates that the job belongs to the current agent+user scope.
// When agentID/userID is empty, all jobs are accessible.
func (t *CronTool) checkJobOwnership(ctx context.Context, jobID, agentID, userID string) (*store.CronJob, *Result) {
	job, ok := t.cronStore.GetJob(ctx, jobID)
	if !ok {
		return nil, ErrorResult(fmt.Sprintf("job %s not found", jobID))
	}

	// Verify ownership
	if agentID != "" && job.AgentID != agentID {
		return nil, ErrorResult(fmt.Sprintf("job %s not found", jobID))
	}
	if userID != "" && job.UserID != userID {
		return nil, ErrorResult(fmt.Sprintf("job %s not found", jobID))
	}

	return job, nil
}

func (t *CronTool) handleUpdate(ctx context.Context, args map[string]any, agentID, userID string) *Result {
	jobID := resolveJobID(args)
	if jobID == "" {
		return ErrorResult("jobId is required for update action")
	}

	existing, errResult := t.checkJobOwnership(ctx, jobID, agentID, userID)
	if errResult != nil {
		return errResult
	}
	if err := store.CheckCronCredentialOwner(ctx, existing); err != nil {
		return ErrorResult("permission denied: cron job uses a credential context owned by another user")
	}

	patchObj, ok := args["patch"].(map[string]any)
	if !ok {
		return ErrorResult("patch object is required for update action")
	}

	// A command payload on update follows the same forms (shell string or argv)
	// and the same gate as add. Parse it out first and drop the raw keys so the
	// generic patch unmarshal below — whose Command field is a structured spec —
	// can't choke on a shell string, and so update can't slip a command payload
	// past the command-enabled gate.
	cmdSpec := parseCronCommandSpec(patchObj)
	delete(patchObj, "command")
	delete(patchObj, "commandArgv")

	var patch store.CronJobPatch
	// Re-marshal and unmarshal to leverage JSON tags
	patchJSON, _ := json.Marshal(patchObj)
	json.Unmarshal(patchJSON, &patch)

	if cmdSpec != nil {
		if !t.commandEnabled {
			return ErrorResult("command cron is disabled on this gateway (set cron.command_enabled=true to allow it)")
		}
		if err := store.ValidateCronCommandSpec(cmdSpec); err != nil {
			return ErrorResult(err.Error())
		}
		patch.Command = cmdSpec
	}

	// Resolve provider override by name (providerId UUID is handled by JSON tags above).
	if name, _ := patchObj["provider"].(string); name != "" {
		pid, errR := t.resolveProviderID(ctx, patchObj)
		if errR != nil {
			return errR
		}
		patch.ProviderID = pid
	}

	validationScheduleObj := map[string]any{}
	messageForValidation := existing.Payload.Message
	if updatedMessage, _ := patchObj["message"].(string); updatedMessage != "" {
		messageForValidation = updatedMessage
	}
	// Support schedule relative fields (in_minutes, delay) or absolute time in patch
	if scheduleObj, ok := patchObj["schedule"].(map[string]any); ok && scheduleObj != nil {
		for key, value := range scheduleObj {
			validationScheduleObj[key] = value
		}
		if stringFromMap(scheduleObj, "kind") == "" {
			if _, hasRel := parseRelativeDuration(scheduleObj); hasRel {
				scheduleObj["kind"] = "at"
			}
		}
		if kind := stringFromMap(scheduleObj, "kind"); kind == "at" {
			ms, err := parseAtSchedule(scheduleObj)
			if err != nil {
				return ErrorResult(err.Error())
			}
			if patch.Schedule == nil {
				patch.Schedule = &store.CronSchedule{Kind: "at"}
			}
			patch.Schedule.AtMS = ms
			calendar, err := validateAtScheduleWeekday(scheduleObj, messageForValidation, *ms)
			if err != nil {
				return ErrorResult(err.Error())
			}
			patch.Schedule.TZ = calendar.Timezone
		}
	} else if patch.Schedule != nil && patch.Schedule.Kind == "at" && patch.Schedule.AtMS != nil {
		if *patch.Schedule.AtMS <= time.Now().UnixMilli() {
			return ErrorResult(fmt.Sprintf("schedule.atMs is in the past (%d). Use the datetime tool to get current time, then set a future timestamp. Current time is %d ms", *patch.Schedule.AtMS, time.Now().UnixMilli()))
		}
	} else {
		// Check if in_minutes or delay was provided at patchObj root
		for _, k := range []string{"in_minutes", "inMinutes", "in_mins", "minutes", "delay", "in_seconds", "in_hours"} {
			if val, exists := patchObj[k]; exists {
				sched := map[string]any{k: val, "kind": "at"}
				if ms, err := parseAtSchedule(sched); err == nil {
					if patch.Schedule == nil {
						patch.Schedule = &store.CronSchedule{Kind: "at"}
					}
					patch.Schedule.AtMS = ms
					calendar, validationErr := validateAtScheduleWeekday(sched, messageForValidation, *ms)
					if validationErr != nil {
						return ErrorResult(validationErr.Error())
					}
					patch.Schedule.TZ = calendar.Timezone
					break
				}
			}
		}
	}

	// A message-only update can introduce a weekday claim that conflicts with
	// the already persisted one-shot date, so validate the effective schedule too.
	var scheduledLocal *scheduledLocalCalendar
	effectiveSchedule := store.MergeCronSchedule(existing.Schedule, patch.Schedule)
	if effectiveSchedule.Kind == "at" && effectiveSchedule.AtMS != nil {
		if _, exists := validationScheduleObj["tz"]; !exists {
			validationScheduleObj["tz"] = effectiveSchedule.TZ
		}
		var err error
		scheduledLocal, err = validateAtScheduleWeekday(
			validationScheduleObj,
			messageForValidation,
			*effectiveSchedule.AtMS,
		)
		if err != nil {
			return ErrorResult(err.Error())
		}
	}

	job, err := t.cronStore.UpdateJob(ctx, jobID, patch)
	if err != nil {
		return ErrorResult(fmt.Sprintf("failed to update cron job: %v", err))
	}

	response := map[string]any{"job": store.RedactCronJobCredentialContext(*job)}
	if scheduledLocal != nil {
		response["scheduledLocal"] = scheduledLocal
	}
	data, _ := json.MarshalIndent(response, "", "  ")
	return NewResult(string(data))
}

func (t *CronTool) handleRemove(ctx context.Context, args map[string]any, agentID, userID string) *Result {
	jobID := resolveJobID(args)
	if jobID == "" {
		return ErrorResult("jobId is required for remove action")
	}

	_, errResult := t.checkJobOwnership(ctx, jobID, agentID, userID)
	if errResult != nil {
		return errResult
	}

	if err := t.cronStore.RemoveJob(ctx, jobID); err != nil {
		return ErrorResult(fmt.Sprintf("failed to remove cron job: %v", err))
	}

	data, _ := json.MarshalIndent(map[string]any{"deleted": true, "jobId": jobID}, "", "  ")
	return NewResult(string(data))
}

func (t *CronTool) handleRun(ctx context.Context, args map[string]any, agentID, userID string) *Result {
	jobID := resolveJobID(args)
	if jobID == "" {
		return ErrorResult("jobId is required for run action")
	}

	existing, errResult := t.checkJobOwnership(ctx, jobID, agentID, userID)
	if errResult != nil {
		return errResult
	}
	if err := store.CheckCronCredentialOwner(ctx, existing); err != nil {
		return ErrorResult("permission denied: cron job uses a credential context owned by another user")
	}

	runMode, _ := args["runMode"].(string)
	force := runMode == "force"

	ran, reason, err := t.cronStore.RunJob(ctx, jobID, force)
	if err != nil {
		return ErrorResult(fmt.Sprintf("failed to run cron job: %v", err))
	}

	result := map[string]any{
		"ran":   ran,
		"jobId": jobID,
	}
	if !ran && reason != "" {
		result["reason"] = reason
	}
	data, _ := json.MarshalIndent(result, "", "  ")
	return NewResult(string(data))
}

func (t *CronTool) handleRuns(ctx context.Context, args map[string]any, agentID, userID string) *Result {
	jobID := resolveJobID(args)

	// Validate ownership if a specific job is requested
	if jobID != "" {
		if _, errResult := t.checkJobOwnership(ctx, jobID, agentID, userID); errResult != nil {
			return errResult
		}
	}

	limit := 20
	if v, ok := numberFromMap(args, "limit"); ok {
		limit = int(v)
	}

	entries, total := t.cronStore.GetRunLog(ctx, jobID, limit, 0)

	result := map[string]any{
		"entries": entries,
		"count":   len(entries),
		"total":   total,
	}
	data, _ := json.MarshalIndent(result, "", "  ")
	return NewResult(string(data))
}

// resolveProviderID resolves an optional per-job provider override from a job/patch
// map. Accepts "providerId" (UUID string) or "provider" (provider name, looked up
// via the provider store). Returns (nil, nil) when neither is present.
func (t *CronTool) resolveProviderID(ctx context.Context, m map[string]any) (*uuid.UUID, *Result) {
	if raw, _ := m["providerId"].(string); raw != "" {
		id, err := uuid.Parse(raw)
		if err != nil {
			return nil, ErrorResult(fmt.Sprintf("invalid providerId %q: must be a UUID", raw))
		}
		return &id, nil
	}
	if name, _ := m["provider"].(string); name != "" {
		if t.providerStore == nil {
			return nil, ErrorResult("provider override by name is unavailable; pass providerId instead")
		}
		pd, err := t.providerStore.GetProviderByName(ctx, name)
		if err != nil || pd == nil {
			return nil, ErrorResult(fmt.Sprintf("provider %q not found; register it first or pass a valid providerId", name))
		}
		return &pd.ID, nil
	}
	return nil, nil
}

// --- helpers ---

func resolveJobID(args map[string]any) string {
	if id, ok := args["jobId"].(string); ok && id != "" {
		return id
	}
	if id, ok := args["id"].(string); ok && id != "" {
		return id
	}
	return ""
}

func stringFromMap(m map[string]any, key string) string {
	v, _ := m[key].(string)
	return v
}

func numberFromMap(m map[string]any, key string) (float64, bool) {
	val, ok := m[key]
	if !ok || val == nil {
		return 0, false
	}
	switch v := val.(type) {
	case float64:
		return v, true
	case float32:
		return float64(v), true
	case int:
		return float64(v), true
	case int64:
		return float64(v), true
	case int32:
		return float64(v), true
	case json.Number:
		if f, err := v.Float64(); err == nil {
			return f, true
		}
	case string:
		if f, err := strconv.ParseFloat(strings.TrimSpace(v), 64); err == nil {
			return f, true
		}
	}
	return 0, false
}

// parseCronCommandSpec extracts an optional deterministic command payload from a
// cron tool "job" object. It accepts either a shell string ("command", wrapped
// as ["sh","-c",...]) or an explicit argv array ("commandArgv"). Returns nil
// when no command fields are present (i.e. it's a normal agent-turn job).
func parseCronCommandSpec(jobObj map[string]any) *store.CronCommandSpec {
	var argv []string
	if raw, ok := jobObj["commandArgv"].([]any); ok {
		for _, v := range raw {
			if s, ok := v.(string); ok {
				argv = append(argv, s)
			}
		}
	}
	if len(argv) == 0 {
		if s, _ := jobObj["command"].(string); s != "" {
			argv = []string{"sh", "-c", s}
		}
	}
	if len(argv) == 0 {
		return nil
	}

	spec := &store.CronCommandSpec{Argv: argv}
	if cwd, _ := jobObj["commandCwd"].(string); cwd != "" {
		spec.Cwd = cwd
	}
	if v, ok := numberFromMap(jobObj, "commandTimeoutSeconds"); ok {
		spec.TimeoutSeconds = int(v)
	}
	if v, ok := numberFromMap(jobObj, "commandNoOutputTimeoutSeconds"); ok {
		spec.NoOutputTimeoutSeconds = int(v)
	}
	if v, ok := numberFromMap(jobObj, "commandOutputMaxBytes"); ok {
		spec.OutputMaxBytes = int(v)
	}
	if env, ok := jobObj["commandEnv"].(map[string]any); ok && len(env) > 0 {
		spec.Env = make(map[string]string, len(env))
		for k, v := range env {
			if s, ok := v.(string); ok {
				spec.Env[k] = s
			}
		}
	}
	return spec
}
