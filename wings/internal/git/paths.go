package git

import (
	"fmt"
	"os"
	"path/filepath"
	"strings"
)

// ResolveVolumePath maps a volume-root-relative path onto an absolute path,
// guaranteeing the result stays inside the volume root. This is the security
// boundary of the executor: even a misbehaving or compromised client cannot
// escape the node's volume tree.
func ResolveVolumePath(volumeRoot, rel string) (string, error) {
	root, err := filepath.Abs(volumeRoot)
	if err != nil {
		return "", fmt.Errorf("invalid volume root: %w", err)
	}
	root = filepath.Clean(root)

	if rel == "" {
		return "", fmt.Errorf("empty path")
	}
	// Reject absolute paths and any '..' segment outright (defence in depth):
	// even a compromised client can never climb out of the volume root.
	if strings.HasPrefix(rel, "/") {
		return "", fmt.Errorf("the requested path is outside the volume root")
	}
	for _, seg := range strings.Split(rel, "/") {
		if seg == ".." {
			return "", fmt.Errorf("the requested path is outside the volume root")
		}
	}

	target := filepath.Join(root, filepath.FromSlash(rel))
	target = filepath.Clean(target)
	if target == root {
		return root, nil
	}
	if !strings.HasPrefix(target, root+string(os.PathSeparator)) {
		return "", fmt.Errorf("the requested path is outside the volume root")
	}

	return target, nil
}
