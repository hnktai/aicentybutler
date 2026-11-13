package cmd

import (
	"fmt"
	"testing"

	"github.com/nextlevelbuilder/goclaw/internal/channels"
)

// TestIsExternalChannel ensures the whitelist matches actual channel type
// constants — a mismatch here silently leaks internal error strings to end
// users (e.g. "zalo" vs "zalo_oa"/"zalo_personal"). Always compare against
// channels.Type* constants, never string literals.
func TestIsExternalChannel(t *testing.T) {
	t.Parallel()

	cases := []struct {
		name        string
		channelType string
		want        bool
	}{
		// Public-facing platforms — errors must be suppressed.
		{"facebook", channels.TypeFacebook, true},
		{"telegram", channels.TypeTelegram, true},
		{"discord", channels.TypeDiscord, true},
		{"feishu", channels.TypeFeishu, true},
		{"whatsapp", channels.TypeWhatsApp, true},
		{"zalo_oa", channels.TypeZaloOA, true},
		{"zalo_personal", channels.TypeZaloPersonal, true},
		{"pancake", channels.TypePancake, true},
		{"slack", channels.TypeSlack, true},

		// Internal / unknown channel types — errors must still surface.
		{"ws", "ws", false},
		{"empty", "", false},
		{"unknown", "myplatform", false},
		// "line" is a Pancake sub-platform, not a top-level channel type.
		{"line_not_top_level", "line", false},
		// Legacy short "zalo" string must NOT match — real constants are zalo_oa / zalo_personal.
		{"zalo_short_form", "zalo", false},
	}

	for _, tc := range cases {
		t.Run(tc.name, func(t *testing.T) {
			if got := isExternalChannel(tc.channelType); got != tc.want {
				t.Fatalf("isExternalChannel(%q) = %v, want %v", tc.channelType, got, tc.want)
			}
		})
	}
}

func TestFormatExternalChannelError(t *testing.T) {
	testErr := fmt.Errorf("rate limit exceeded (TPM exceeded)")

	// 1. Zalo Personal for Ba Cường
	msg, suppressed := formatExternalChannelError(channels.TypeZaloPersonal, "quangia", "6631971342486485220", map[string]string{}, testErr)
	if !suppressed {
		t.Fatal("expected suppressed=true for Zalo Personal")
	}
	expectedBaCuong := "Dạ Ba Cường đợi em một chút, hệ thống vừa bị nghẽn mạng tích tắc. Em đã ghi nhận và sẽ phản hồi Ba Cường ngay ạ!"
	if msg != expectedBaCuong {
		t.Fatalf("expected %q, got %q", expectedBaCuong, msg)
	}

	// 2. Zalo with named sender
	msg2, suppressed2 := formatExternalChannelError(channels.TypeZaloOA, "zalo_oa_channel", "12345", map[string]string{"sender_name": "Anh Tuấn"}, testErr)
	if !suppressed2 {
		t.Fatal("expected suppressed=true for Zalo OA")
	}
	expectedAnhTuan := "Dạ Anh Tuấn đợi em một chút, hệ thống vừa bị nghẽn mạng tích tắc. Em đã ghi nhận và sẽ phản hồi Anh Tuấn ngay ạ!"
	if msg2 != expectedAnhTuan {
		t.Fatalf("expected %q, got %q", expectedAnhTuan, msg2)
	}

	// 2b. Zalo with display_name (Zalo Personal metadata format)
	msg2b, suppressed2b := formatExternalChannelError(channels.TypeZaloPersonal, "giasu-nguyenhung", "8807003040858051776", map[string]string{"display_name": "Minh Khôi"}, testErr)
	if !suppressed2b {
		t.Fatal("expected suppressed=true for Zalo Personal with display_name")
	}
	expectedMinhKhoi := "Dạ Minh Khôi đợi em một chút, hệ thống vừa bị nghẽn mạng tích tắc. Em đã ghi nhận và sẽ phản hồi Minh Khôi ngay ạ!"
	if msg2b != expectedMinhKhoi {
		t.Fatalf("expected %q, got %q", expectedMinhKhoi, msg2b)
	}

	// 3. Zalo with unknown sender -> default "Gia chủ"
	msg3, suppressed3 := formatExternalChannelError(channels.TypeZaloPersonal, "zalo_channel", "99999", map[string]string{}, testErr)
	if !suppressed3 {
		t.Fatal("expected suppressed=true for Zalo Personal")
	}
	expectedGiaChu := "Dạ Gia chủ đợi em một chút, hệ thống vừa bị nghẽn mạng tích tắc. Em đã ghi nhận và sẽ phản hồi Gia chủ ngay ạ!"
	if msg3 != expectedGiaChu {
		t.Fatalf("expected %q, got %q", expectedGiaChu, msg3)
	}

	// 4. Telegram -> suppressed with empty string
	msg4, suppressed4 := formatExternalChannelError(channels.TypeTelegram, "tele", "123", map[string]string{}, testErr)
	if !suppressed4 || msg4 != "" {
		t.Fatalf("expected suppressed=true and empty msg for Telegram, got %q, %v", msg4, suppressed4)
	}

	// 5. Facebook -> suppressed with empty string
	msg5, suppressed5 := formatExternalChannelError(channels.TypeFacebook, "fb", "123", map[string]string{}, testErr)
	if !suppressed5 || msg5 != "" {
		t.Fatalf("expected suppressed=true and empty msg for FB, got %q, %v", msg5, suppressed5)
	}

	// 6. Internal "ws" -> not suppressed, returns formatted error
	msg6, suppressed6 := formatExternalChannelError("ws", "ws", "123", map[string]string{}, testErr)
	if suppressed6 || msg6 == "" {
		t.Fatalf("expected suppressed=false and non-empty msg for ws, got %q, %v", msg6, suppressed6)
	}
}
