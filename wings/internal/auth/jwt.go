package auth

import (
	"context"
	"crypto/hmac"
	"crypto/sha256"
	"encoding/base64"
	"encoding/json"
	"fmt"
	"net/http"
	"strings"
	"time"
)

// Claims is the JWT payload the panel signs with the node daemon token.
type Claims struct {
	Iat        int64  `json:"iat"`
	Exp        int64  `json:"exp"`
	ServerUUID string `json:"server_uuid"`
	Sub        string `json:"sub"`
}

// Verifier validates HS256 JWTs signed with the Wings daemon secret.
type Verifier struct {
	secret []byte
}

// NewVerifier creates a Verifier with the (decrypted) daemon token.
func NewVerifier(secret string) *Verifier {
	return &Verifier{secret: []byte(secret)}
}

// TokenFromRequest extracts the token from the X-Access-Token header.
func (v *Verifier) TokenFromRequest(r *http.Request) string {
	return r.Header.Get("X-Access-Token")
}

// Verify validates the JWT signature, exp/iat window, and returns the claims.
func (v *Verifier) Verify(token string) (*Claims, error) {
	parts := strings.SplitN(token, ".", 3)
	if len(parts) != 3 {
		return nil, fmt.Errorf("invalid token format")
	}

	unsigned := parts[0] + "." + parts[1]
	expectedSig := base64URLEncode(hmacSHA256([]byte(unsigned), v.secret))
	if !hmac.Equal([]byte(parts[2]), []byte(expectedSig)) {
		return nil, fmt.Errorf("invalid signature")
	}

	payloadBytes, err := base64URLDecode(parts[1])
	if err != nil {
		return nil, fmt.Errorf("invalid payload: %w", err)
	}

	var claims Claims
	if err := json.Unmarshal(payloadBytes, &claims); err != nil {
		return nil, fmt.Errorf("invalid claims: %w", err)
	}

	now := time.Now().Unix()
	if now > claims.Exp {
		return nil, fmt.Errorf("token expired")
	}
	if now < claims.Iat-30 {
		return nil, fmt.Errorf("token not yet valid")
	}

	return &claims, nil
}

// Require wraps a handler, returning 401 for invalid tokens.
func (v *Verifier) Require(handler http.HandlerFunc) http.HandlerFunc {
	return func(w http.ResponseWriter, r *http.Request) {
		token := v.TokenFromRequest(r)
		if token == "" {
			http.Error(w, `{"error":"missing X-Access-Token header"}`, http.StatusUnauthorized)
			return
		}
		claims, err := v.Verify(token)
		if err != nil {
			http.Error(w, fmt.Sprintf(`{"error":"unauthorized: %s"}`, err.Error()), http.StatusUnauthorized)
			return
		}
		ctx := context.WithValue(r.Context(), claimsKey{}, claims)
		handler(w, r.WithContext(ctx))
	}
}

type claimsKey struct{}

// ClaimsFromContext retrieves the verified claims from the request context.
func ClaimsFromContext(r *http.Request) *Claims {
	c, _ := r.Context().Value(claimsKey{}).(*Claims)
	return c
}

func base64URLEncode(data []byte) string {
	return strings.TrimRight(base64.URLEncoding.EncodeToString(data), "=")
}

func base64URLDecode(s string) ([]byte, error) {
	padding := 4 - len(s)%4
	if padding < 4 {
		s += strings.Repeat("=", padding)
	}
	return base64.URLEncoding.DecodeString(s)
}

func hmacSHA256(data, key []byte) []byte {
	h := hmac.New(sha256.New, key)
	h.Write(data)
	return h.Sum(nil)
}
