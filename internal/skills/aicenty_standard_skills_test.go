package skills

import (
	"os"
	"path/filepath"
	"runtime"
	"strings"
	"testing"
)

func TestAicentyStandardSkillsAreBundledFromSource(t *testing.T) {
	_, filename, _, ok := runtime.Caller(0)
	if !ok {
		t.Fatal("resolve test source path")
	}
	skillsDir := filepath.Join(filepath.Dir(filename), "..", "..", "skills")
	required := map[string][]string{
		"document-to-pdf":          {"SKILL.md", "scripts/document_to_pdf_client.py"},
		"images-to-pdf":            {"SKILL.md", "scripts/images_to_pdf.py"},
		"quangia-image-generation": {"SKILL.md", "agents/openai.yaml"},
		"quangia-mobile-messaging": {"SKILL.md", "agents/openai.yaml", "references/care-playbook.md"},
	}

	for slug, files := range required {
		t.Run(slug, func(t *testing.T) {
			for _, rel := range files {
				path := filepath.Join(skillsDir, slug, filepath.FromSlash(rel))
				info, err := os.Stat(path)
				if err != nil {
					t.Fatalf("required bundled skill file %s: %v", path, err)
				}
				if info.IsDir() || info.Size() == 0 {
					t.Fatalf("required bundled skill file is empty or a directory: %s", path)
				}
			}

			content, err := os.ReadFile(filepath.Join(skillsDir, slug, "SKILL.md"))
			if err != nil {
				t.Fatal(err)
			}
			if !strings.Contains(string(content), "name: "+slug) {
				t.Fatalf("SKILL.md does not declare slug %q", slug)
			}
		})
	}
}
