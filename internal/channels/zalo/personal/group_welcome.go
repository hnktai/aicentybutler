package personal

import (
	"context"
	"log/slog"

	"github.com/nextlevelbuilder/goclaw/internal/bus"
	"github.com/nextlevelbuilder/goclaw/internal/channels"
)

const firstGroupWelcomeMessage = `🎩 Quản Gia xin chào cả nhà! Quản Gia đã sẵn sàng đồng hành cùng gia đình.

Dùng nhanh:
• Trong nhóm: @Quản Gia để nhắc lịch, việc nhà hoặc nhờ hỗ trợ.
• Học tập 1-1: các con nhắn riêng Quản Gia; Quản Gia sẽ sắp xếp Thầy/Cô đúng môn kèm riêng.
• Mỗi thành viên có thể nhắn riêng để được hỗ trợ đúng nhu cầu.

Từ tin nhắn sau, cả nhà chỉ cần @Quản Gia khi muốn gọi nhé ạ.`

func (c *Channel) claimFirstGroupWelcome(threadID string) bool {
	if threadID == "" || c.GroupHistory() == nil {
		return false
	}
	if _, loaded := c.groupWelcomeClaims.LoadOrStore(threadID, struct{}{}); loaded {
		return false
	}
	return !c.GroupHistory().HasMarker(threadID, channels.GroupWelcomeMarkerV1)
}

func (c *Channel) releaseFirstGroupWelcome(threadID string) {
	c.groupWelcomeClaims.Delete(threadID)
}

func (c *Channel) sendFirstGroupWelcome(ctx context.Context, threadID string) error {
	return c.Send(ctx, bus.OutboundMessage{
		Channel: c.Name(),
		ChatID:  threadID,
		Content: firstGroupWelcomeMessage,
		Metadata: map[string]string{
			"group_id": threadID,
		},
	})
}

func (c *Channel) welcomeFirstGroupMessage(ctx context.Context, threadID string) {
	if !c.claimFirstGroupWelcome(threadID) {
		return
	}
	if err := c.sendFirstGroupWelcome(ctx, threadID); err != nil {
		c.releaseFirstGroupWelcome(threadID)
		slog.Warn("zalo_personal.group_welcome_send_failed", "group_id", threadID, "error", err)
		return
	}
	if err := c.GroupHistory().RecordMarker(threadID, channels.GroupWelcomeMarkerV1); err != nil {
		slog.Warn("zalo_personal.group_welcome_marker_failed", "group_id", threadID, "error", err)
	}
	slog.Info("zalo_personal.group_welcome_sent", "group_id", threadID, "version", 1)
}
