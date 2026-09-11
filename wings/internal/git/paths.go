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

	target := filepath.Clean(filepath.Join(root, filepath.Clean("/"+rel)))
	if !strings.HasPrefix(target, root+string(os.PathSeparator)) {
		return "", fmt.Errorf("the requested path is outside the volume root")
	}

	return target, nil
}