package main

import (
	"flag"
	"log"
	"net/http"
	"os"

	"github.com/Nex-Devz/PteroGit/wings/internal/auth"
	"github.com/Nex-Devz/PteroGit/wings/internal/server"
)

func main() {
	addr := flag.String("addr", ":9100", "HTTP listen address")
	token := flag.String("daemon-token", "", "Node daemon token used to verify panel JWTs")
	volumeRoot := flag.String("volume-root", "", "Path to the node's volume root (usually <root_directory>/volumes)")
	flag.Parse()

	if *token == "" {
		*token = os.Getenv("PTEROGIT_DAEMON_TOKEN")
	}
	if *volumeRoot == "" {
		*volumeRoot = os.Getenv("PTEROGIT_VOLUME_ROOT")
	}

	if *token == "" || *volumeRoot == "" {
		log.Fatalf("both --daemon-token and --volume-root are required (or set via PTEROGIT_DAEMON_TOKEN / PTEROGIT_VOLUME_ROOT)")
	}

	verifier := auth.NewVerifier(*token)
	handler := server.NewHandler(verifier, *volumeRoot)

	mux := http.NewServeMux()
	handler.Register(mux)

	log.Printf("PteroGit Wings executor listening on %s (volume root: %s)", *addr, *volumeRoot)
	if err := http.ListenAndServe(*addr, mux); err != nil {
		log.Fatalf("HTTP server error: %v", err)
	}
}
