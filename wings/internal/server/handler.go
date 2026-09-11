package server

import (
	"encoding/json"
	"net/http"
	"os"
	"strings"

	"github.com/Nex-Devz/PteroGit/wings/internal/auth"
	"github.com/Nex-Devz/PteroGit/wings/internal/git"
)

// Handler wires the PteroGit git channel onto an HTTP server.
//
//	POST /api/servers/:uuid/git      git execution or file operations
//	GET  /api/servers/:uuid/git/health  liveness + channel presence probe
type Handler struct {
	verifier   *auth.Verifier
	volumeRoot string
}

// NewHandler creates a Handler for the given daemon token and volume root.
func NewHandler(verifier *auth.Verifier, volumeRoot string) *Handler {
	return &Handler{verifier: verifier, volumeRoot: volumeRoot}
}

// Register mounts the git channel routes onto mux.
func (h *Handler) Register(mux *http.ServeMux) {
	mux.HandleFunc("/api/servers/", h.router)
}

func (h *Handler) router(w http.ResponseWriter, r *http.Request) {
	const prefix = "/api/servers/"
	if !strings.HasPrefix(r.URL.Path, prefix) {
		http.NotFound(w, r)
		return
	}

	parts := strings.SplitN(strings.TrimPrefix(r.URL.Path, prefix), "/", 2)
	if len(parts) < 2 {
		http.NotFound(w, r)
		return
	}

	uuid := parts[0]
	rest := parts[1]

	if rest == "git" && r.Method == http.MethodPost {
		h.handleGit(w, r, uuid)
		return
	}
	if rest == "git/health" && r.Method == http.MethodGet {
		h.handleHealth(w, r, uuid)
		return
	}

	http.NotFound(w, r)
}

type gitRequest struct {
	Cwd   string   `json:"cwd"`
	Args  []string `json:"args"`
	Token string   `json:"token"`
	File  *fileOp  `json:"file"`
}

type fileOp struct {
	Op      string `json:"op"`
	Path    string `json:"path"`
	Content string `json:"content"`
}

func writeJSON(w http.ResponseWriter, status int, value any) {
	w.Header().Set("Content-Type", "application/json")
	w.WriteHeader(status)
	_ = json.NewEncoder(w).Encode(value)
}

func (h *Handler) handleGit(w http.ResponseWriter, r *http.Request, uuid string) {
	token := h.verifier.TokenFromRequest(r)
	if token == "" {
		writeJSON(w, http.StatusUnauthorized, map[string]string{"error": "missing X-Access-Token header"})
		return
	}

	claims, err := h.verifier.Verify(token)
	if err != nil {
		writeJSON(w, http.StatusUnauthorized, map[string]string{"error": "unauthorized: " + err.Error()})
		return
	}
	if claims.ServerUUID != uuid {
		writeJSON(w, http.StatusForbidden, map[string]string{"error": "token does not match the requested server"})
		return
	}

	var req gitRequest
	if err := json.NewDecoder(r.Body).Decode(&req); err != nil {
		writeJSON(w, http.StatusBadRequest, map[string]string{"error": "invalid JSON body"})
		return
	}

	// File channel (stat/read/write/mkdir/chown)
	if req.File != nil {
		result := git.FileOp(h.volumeRoot, req.File.Op, req.File.Path, req.File.Content)
		writeJSON(w, http.StatusOK, map[string]any{
			"ok":    result.OK,
			"data":  result,
			"error": result.Error,
		})
		return
	}

	// Git execution
	if len(req.Args) == 0 {
		writeJSON(w, http.StatusBadRequest, map[string]string{"error": "the args array is required"})
		return
	}

	result, err := git.Exec(h.volumeRoot, req.Cwd, req.Args, req.Token)
	if err != nil {
		writeJSON(w, http.StatusInternalServerError, map[string]string{"error": err.Error()})
		return
	}

	writeJSON(w, http.StatusOK, result)
}

func (h *Handler) handleHealth(w http.ResponseWriter, r *http.Request, uuid string) {
	absPath, err := git.ResolveVolumePath(h.volumeRoot, uuid)
	if err != nil {
		writeJSON(w, http.StatusNotFound, map[string]string{"ok": "false"})
		return
	}

	info, err := os.Stat(absPath)
	if err != nil || !info.IsDir() {
		writeJSON(w, http.StatusNotFound, map[string]string{"ok": "false"})
		return
	}

	writeJSON(w, http.StatusOK, map[string]any{"ok": true, "git": "pterogit"})
}
