package auth

import (
	"crypto/hmac"
	"crypto/sha256"
	"encoding/json"
	"strings"
	"testing"
	"time"
)

// signToken reproduces the panel's HS256 signing so the verifier can be tested
// against a token produced with the exact same algorithm.
func signToken(secret string, payload map[string]any) string {
	header := base64URLEncode([]byte(`{"alg":"HS256","typ":"JWT"}`))
	body, _ := json.Marshal(payload)
	claimSegment := base64URLEncode(body)

	unsigned := header + "." + claimSegment
	mac := hmac.New(sha256.New, []byte(secret))
	mac.Write([]byte(unsigned))
	signature := base64URLEncode(mac.Sum(nil))

	return unsigned + "." + signature
}

func TestVerify_ValidToken(t *testing.T) {
	secret := "node-daemon-secret"
	payload := map[string]any{
		"iat":         time.Now().Unix(),
		"exp":         time.Now().Add(time.Minute).Unix(),
		"server_uuid": "abc-123",
		"sub":         "pterogit",
	}

	token := signToken(secret, payload)

	claims, err := NewVerifier(secret).Verify(token)
	if err != nil {
		t.Fatalf("expected verification to succeed: %v", err)
	}
	if claims.ServerUUID != "abc-123" {
		t.Fatalf("server_uuid = %q, want %q", claims.ServerUUID, "abc-123")
	}
}

func TestVerify_BadSignature(t *testing.T) {
	token := signToken("right-secret", map[string]any{
		"iat":         time.Now().Unix(),
		"exp":         time.Now().Add(time.Minute).Unix(),
		"server_uuid": "abc",
	})

	if _, err := NewVerifier("wrong-secret").Verify(token); err == nil {
		t.Fatal("expected signature mismatch to fail")
	}
}

func TestVerify_Expired(t *testing.T) {
	token := signToken("secret", map[string]any{
		"iat":         time.Now().Add(-2 * time.Minute).Unix(),
		"exp":         time.Now().Add(-time.Minute).Unix(),
		"server_uuid": "abc",
	})

	if _, err := NewVerifier("secret").Verify(token); err == nil {
		t.Fatal("expected expired token to fail")
	}
}

func TestVerify_TamperedPayload(t *testing.T) {
	token := signToken("secret", map[string]any{
		"iat":         time.Now().Unix(),
		"exp":         time.Now().Add(time.Minute).Unix(),
		"server_uuid": "abc",
	})

	parts := strings.Split(token, ".")

	// Flip one character of the claims segment.
	claims := []byte(parts[1])
	claims[len(claims)-3] ^= 0x01
	parts[1] = string(claims)

	tampered := strings.Join(parts, ".")

	if _, err := NewVerifier("secret").Verify(tampered); err == nil {
		t.Fatal("expected tampered token to fail")
	}
}
