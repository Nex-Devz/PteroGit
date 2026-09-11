//go:build !linux

package git

import "syscall"

// dropToVolumeOwner is a no-op on non-Linux platforms (Wings only ships for
// Linux; this build keeps the module compilable on developer machines).
func dropToVolumeOwner(dir string) *syscall.SysProcAttr {
	return nil
}

// volumeOwnerID is a no-op on non-Linux platforms.
func volumeOwnerID(path string) (int, int, bool) {
	return 0, 0, false
}
