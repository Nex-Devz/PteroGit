package git

import (
	"fmt"
	"os"
	"os/exec"
	"path/filepath"
	"strings"
)

// gitBin is the git binary used on the node. Exposed as a variable so tests
// (and unusual installations) can point it somewhere else; Wings hosts should
// keep the default.
var gitBin = "/usr/bin/git"

// ExecResult is returned for every git command execution.
type ExecResult struct {
	Exit   int    `json:"exit"`
	Stdout string `json:"stdout"`
	Stderr string `json:"stderr"`
}

// Exec runs git with the working directory at volumeRoot/cwdRel.
// If token is non-empty, GIT_ASKPASS injects it without leaking into /proc args.
// The process runs as the volume directory's owner (the container user).
func Exec(volumeRoot, cwdRel string, args []string, token string) (*ExecResult, error) {
	absCwd, err := resolveVolumeDir(volumeRoot, cwdRel)
	if err != nil {
		return nil, err
	}

	env := os.Environ()
	env = append(env, "GIT_TERMINAL_PROMPT=0", "GIT_ASKPASS=echo")

	if token != "" {
		askpassPath, err := createAskpass(token)
		if err != nil {
			return nil, fmt.Errorf("failed to create askpass: %w", err)
		}
		defer os.Remove(askpassPath)

		env = append(env,
			"GIT_ASKPASS="+askpassPath,
			"GIT_TOKEN="+token,
			"GIT_USERNAME=x-access-token",
		)
	}

	// Prepend credential.helper= to suppress stored credentials.
	cmdArgs := []string{"-c", "credential.helper=", "-C", absCwd}
	cmdArgs = append(cmdArgs, args...)

	cmd := exec.Command(gitBin, cmdArgs...)
	cmd.Env = env
	cmd.SysProcAttr = dropToVolumeOwner(absCwd)

	var stdout, stderr strings.Builder
	cmd.Stdout = &stdout
	cmd.Stderr = &stderr

	err = cmd.Run()
	exitCode := 0
	if err != nil {
		if exitErr, ok := err.(*exec.ExitError); ok {
			exitCode = exitErr.ExitCode()
		} else {
			return nil, fmt.Errorf("failed to execute git: %w", err)
		}
	}

	out := stdout.String()
	errOut := stderr.String()
	if token != "" {
		out = redactToken(out, token)
		errOut = redactToken(errOut, token)
	}

	return &ExecResult{Exit: exitCode, Stdout: out, Stderr: errOut}, nil
}

func resolveVolumeDir(volumeRoot, cwdRel string) (string, error) {
	absCwd, err := ResolveVolumePath(volumeRoot, cwdRel)
	if err != nil {
		return "", err
	}
	info, err := os.Stat(absCwd)
	if err != nil {
		return "", fmt.Errorf("the server directory does not exist yet: %w", err)
	}
	if !info.IsDir() {
		return "", fmt.Errorf("the server path is not a directory")
	}
	return absCwd, nil
}

func createAskpass(token string) (string, error) {
	script := "#!/bin/sh\nexec echo \"$PTEROGIT_TOKEN\"\n"
	tmp := filepath.Join(os.TempDir(), fmt.Sprintf("pterogit-askpass-%d", os.Getpid()))
	if err := os.WriteFile(tmp, []byte(script), 0700); err != nil {
		return "", err
	}
	return tmp, nil
}

func redactToken(s, token string) string {
	return strings.ReplaceAll(s, token, "[REDACTED]")
}
