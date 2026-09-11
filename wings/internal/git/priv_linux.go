//go:build linux

package git

import (
	"os"
	"syscall"
)

// dropToVolumeOwner makes git run as the volume directory's owner (the
// container user) instead of the executor's own user (typically root).
func dropToVolumeOwner(dir string) *syscall.SysProcAttr {
	uid, gid, ok := volumeOwnerID(dir)
	if !ok {
		return nil
	}
	// Already the volume owner (tests, or a non-root executor): nothing to do.
	// A non-root process cannot setuid; attempting it fails with EPERM.
	if os.Geteuid() != 0 {
		return nil
	}
	// Root executor: drop to the container user.
	return &syscall.SysProcAttr{
		Credential: &syscall.Credential{Uid: uint32(uid), Gid: uint32(gid)},
	}
}

// volumeOwnerID resolves the owning uid/gid of a path on Linux.
func volumeOwnerID(path string) (int, int, bool) {
	info, err := os.Stat(path)
	if err != nil {
		return 0, 0, false
	}
	stat, ok := info.Sys().(*syscall.Stat_t)
	if !ok {
		return 0, 0, false
	}
	return int(stat.Uid), int(stat.Gid), true
}
