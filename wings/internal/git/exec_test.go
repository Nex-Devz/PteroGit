package git

import (
	"os"
	"path/filepath"
	"strings"
	"testing"
)

func init() {
	// Exec hardcodes /usr/bin/git on Wings hosts; tests may run anywhere.
	gitBin = "git"
}

func TestResolveVolumePath_StaysInside(t *testing.T) {
	root := t.TempDir()

	target, err := ResolveVolumePath(root, "myserver/.git/HEAD")
	if err != nil {
		t.Fatalf("unexpected error: %v", err)
	}

	abs, _ := filepath.Abs(root)
	if !strings.HasPrefix(target, abs+string(os.PathSeparator)) {
		t.Fatalf("resolved path %q escaped volume root %q", target, abs)
	}
}

func TestResolveVolumePath_RejectsTraversal(t *testing.T) {
	root := t.TempDir()

	for _, rel := range []string{"../../etc/passwd", "..secret", "a/../../etc/passwd", "/etc/passwd"} {
		if _, err := ResolveVolumePath(root, rel); err == nil {
			t.Fatalf("expected traversal path %q to be rejected", rel)
		}
	}
}

func TestExec_RunsGitInServerDir(t *testing.T) {
	root := t.TempDir()
	repo := filepath.Join(root, "myserver")
	if err := os.MkdirAll(repo, 0755); err != nil {
		t.Fatalf("mkdir: %v", err)
	}

	res, err := Exec(root, "myserver", []string{"init", "-b", "main"}, "")
	if err != nil {
		t.Fatalf("exec failed: %v", err)
	}
	if res.Exit != 0 {
		t.Fatalf("git init exit=%d stderr=%s", res.Exit, res.Stderr)
	}

	res, err = Exec(root, "myserver", []string{"branch", "--show-current"}, "")
	if err != nil {
		t.Fatalf("branch exec failed: %v", err)
	}
	if strings.TrimSpace(res.Stdout) != "main" {
		t.Fatalf("current branch = %q, want %q", res.Stdout, "main")
	}
}

func TestExec_MissingDirectory(t *testing.T) {
	root := t.TempDir()

	if _, err := Exec(root, "does-not-exist", []string{"status"}, ""); err == nil {
		t.Fatal("expected an error for a missing server directory")
	}
}

func TestExec_RejectsTraversal(t *testing.T) {
	root := t.TempDir()

	if _, err := Exec(root, "../../etc", []string{"--version"}, ""); err == nil {
		t.Fatal("expected traversal cwd to be rejected")
	}
}