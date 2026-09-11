package git

import (
	"fmt"
	"os"
	"path/filepath"
)

// FileResult is the response for a file channel operation.
type FileResult struct {
	OK      bool   `json:"ok"`
	Exists  bool   `json:"exists,omitempty"`
	Content string `json:"content,omitempty"`
	Error   string `json:"error,omitempty"`
}

// FileOp handles stat/read/write/mkdir/chown for a single path under volumeRoot.
// Paths are RELATIVE to the volume root and validated against traversal.
func FileOp(volumeRoot, op, relPath, content string) *FileResult {
	absPath, err := ResolveVolumePath(volumeRoot, relPath)
	if err != nil {
		return &FileResult{Error: err.Error()}
	}

	switch op {
	case "stat":
		_, err := os.Stat(absPath)
		if os.IsNotExist(err) {
			return &FileResult{OK: true, Exists: false}
		}
		if err != nil {
			return &FileResult{OK: true, Exists: false}
		}
		return &FileResult{OK: true, Exists: true}

	case "read":
		data, err := os.ReadFile(absPath)
		if os.IsNotExist(err) {
			return &FileResult{OK: true, Exists: false}
		}
		if err != nil {
			return &FileResult{Error: err.Error()}
		}
		return &FileResult{OK: true, Exists: true, Content: string(data)}

	case "write":
		if err := os.MkdirAll(filepath.Dir(absPath), 0755); err != nil {
			return &FileResult{Error: fmt.Sprintf("failed to create parent: %v", err)}
		}
		if err := os.WriteFile(absPath, []byte(content), 0644); err != nil {
			return &FileResult{Error: fmt.Sprintf("failed to write: %v", err)}
		}
		_ = chownToVolume(absPath)
		return &FileResult{OK: true}

	case "mkdir":
		if err := os.MkdirAll(absPath, 0755); err != nil {
			return &FileResult{Error: fmt.Sprintf("failed to create: %v", err)}
		}
		_ = chownRecursive(absPath)
		return &FileResult{OK: true}

	case "chown":
		if err := chownToVolume(absPath); err != nil {
			return &FileResult{Error: fmt.Sprintf("failed to chown: %v", err)}
		}
		return &FileResult{OK: true}

	default:
		return &FileResult{Error: "unknown file op: " + op}
	}
}

func chownToVolume(path string) error {
	parent := filepath.Dir(path)
	uid, gid, ok := volumeOwnerID(parent)
	if !ok {
		return nil
	}
	return os.Chown(path, uid, gid)
}

func chownRecursive(path string) error {
	uid, gid, ok := volumeOwnerID(path)
	if !ok {
		return nil
	}
	return filepath.Walk(path, func(p string, fi os.FileInfo, err error) error {
		if err != nil {
			return err
		}
		return os.Chown(p, uid, gid)
	})
}
