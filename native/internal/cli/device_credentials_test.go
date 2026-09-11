package cli

import (
	"os"
	"path/filepath"
	"runtime"
	"testing"
	"time"
)

func TestFileDeviceCredentialStoreSavesAndLoadsAnActiveCredential(t *testing.T) {
	now := time.Date(2026, time.August, 31, 8, 0, 0, 0, time.UTC)
	path := filepath.Join(t.TempDir(), "crucible", "device-credentials.json")
	store := fileDeviceCredentialStore{Path: path, Now: func() time.Time { return now }}
	want := ApprovedDeviceCredential{
		Server:      "https://crucible.example.test/",
		LeaseID:     "lease-1",
		Protocol:    "postgresql",
		DeviceID:    "device-1",
		AccessToken: "secret-token",
		ExpiresAt:   now.Add(5 * time.Minute),
	}

	if err := store.Save(want); err != nil {
		t.Fatal(err)
	}

	got, found, err := store.Load("https://crucible.example.test", "lease-1")
	if err != nil {
		t.Fatal(err)
	}
	if !found {
		t.Fatal("expected saved device credential")
	}
	if got.Server != "https://crucible.example.test" || got.AccessToken != want.AccessToken || got.DeviceID != want.DeviceID {
		t.Fatalf("unexpected saved credential: %#v", got)
	}
	if _, found, err := store.Load("https://crucible.example.test", "another-lease"); err != nil || found {
		t.Fatalf("credential must remain scoped to its lease: found=%v err=%v", found, err)
	}
	if _, found, err := store.Load("https://another.example.test", "lease-1"); err != nil || found {
		t.Fatalf("credential must remain scoped to its server: found=%v err=%v", found, err)
	}

	if runtime.GOOS != "windows" {
		info, err := os.Stat(path)
		if err != nil {
			t.Fatal(err)
		}
		if info.Mode().Perm() != 0o600 {
			t.Fatalf("credential file mode is %o, want 600", info.Mode().Perm())
		}
	}
}

func TestFileDeviceCredentialStoreReplacesTheCredentialForTheSameLease(t *testing.T) {
	now := time.Date(2026, time.August, 31, 8, 0, 0, 0, time.UTC)
	store := fileDeviceCredentialStore{
		Path: filepath.Join(t.TempDir(), "device-credentials.json"),
		Now:  func() time.Time { return now },
	}

	for _, token := range []string{"first-token", "second-token"} {
		if err := store.Save(ApprovedDeviceCredential{
			Server:      "https://crucible.example.test",
			LeaseID:     "lease-1",
			Protocol:    "mysql",
			DeviceID:    "device-1",
			AccessToken: token,
			ExpiresAt:   now.Add(5 * time.Minute),
		}); err != nil {
			t.Fatal(err)
		}
	}

	got, found, err := store.Load("https://crucible.example.test", "lease-1")
	if err != nil {
		t.Fatal(err)
	}
	if !found || got.AccessToken != "second-token" {
		t.Fatalf("unexpected replacement credential: found=%v credential=%#v", found, got)
	}
}

func TestFileDeviceCredentialStoreIgnoresCredentialsNearExpiry(t *testing.T) {
	now := time.Date(2026, time.August, 31, 8, 0, 0, 0, time.UTC)
	store := fileDeviceCredentialStore{
		Path: filepath.Join(t.TempDir(), "device-credentials.json"),
		Now:  func() time.Time { return now },
	}
	if err := store.Save(ApprovedDeviceCredential{
		Server:      "https://crucible.example.test",
		LeaseID:     "lease-1",
		Protocol:    "postgresql",
		DeviceID:    "device-1",
		AccessToken: "secret-token",
		ExpiresAt:   now.Add(10 * time.Second),
	}); err != nil {
		t.Fatal(err)
	}

	_, found, err := store.Load("https://crucible.example.test", "lease-1")
	if err != nil {
		t.Fatal(err)
	}
	if found {
		t.Fatal("expected near-expiry device credential to be ignored")
	}
}

func TestFileDeviceCredentialStoreRejectsBroadFilePermissions(t *testing.T) {
	if runtime.GOOS == "windows" {
		t.Skip("Windows does not expose POSIX file permissions")
	}

	now := time.Date(2026, time.August, 31, 8, 0, 0, 0, time.UTC)
	path := filepath.Join(t.TempDir(), "device-credentials.json")
	store := fileDeviceCredentialStore{Path: path, Now: func() time.Time { return now }}
	if err := store.Save(ApprovedDeviceCredential{
		Server:      "https://crucible.example.test",
		LeaseID:     "lease-1",
		Protocol:    "postgresql",
		DeviceID:    "device-1",
		AccessToken: "secret-token",
		ExpiresAt:   now.Add(5 * time.Minute),
	}); err != nil {
		t.Fatal(err)
	}
	if err := os.Chmod(path, 0o644); err != nil {
		t.Fatal(err)
	}

	if _, _, err := store.Load("https://crucible.example.test", "lease-1"); err == nil {
		t.Fatal("expected broad file permissions to be rejected")
	}
}
