package cli

import (
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"os"
	"path/filepath"
	"runtime"
	"strings"
	"time"
)

const deviceCredentialFileVersion = 1
const maximumDeviceCredentialFileBytes = 64 << 10

type ApprovedDeviceCredential struct {
	Server      string    `json:"server"`
	LeaseID     string    `json:"lease_id"`
	Protocol    string    `json:"protocol"`
	DeviceID    string    `json:"device_authorization_id"`
	AccessToken string    `json:"access_token"`
	ExpiresAt   time.Time `json:"expires_at"`
}

type DeviceCredentialStore interface {
	Load(server, leaseID string) (ApprovedDeviceCredential, bool, error)
	Save(credential ApprovedDeviceCredential) error
}

type fileDeviceCredentialStore struct {
	Path string
	Now  func() time.Time
}

type deviceCredentialFile struct {
	Version     int                        `json:"version"`
	Credentials []ApprovedDeviceCredential `json:"credentials"`
}

func newFileDeviceCredentialStore() fileDeviceCredentialStore {
	return fileDeviceCredentialStore{Now: time.Now}
}

func (store fileDeviceCredentialStore) Load(server, leaseID string) (ApprovedDeviceCredential, bool, error) {
	file, err := store.read()
	if errors.Is(err, os.ErrNotExist) {
		return ApprovedDeviceCredential{}, false, nil
	}
	if err != nil {
		return ApprovedDeviceCredential{}, false, err
	}

	now := store.now()
	server = normalizeServer(server)
	for _, credential := range file.Credentials {
		if credential.Server == server && credential.LeaseID == leaseID && credential.ExpiresAt.After(now.Add(15*time.Second)) {
			if !credential.valid() {
				return ApprovedDeviceCredential{}, false, errors.New("stored device credential is invalid")
			}

			return credential, true, nil
		}
	}

	return ApprovedDeviceCredential{}, false, nil
}

func (store fileDeviceCredentialStore) Save(credential ApprovedDeviceCredential) error {
	credential.Server = normalizeServer(credential.Server)
	if !credential.valid() {
		return errors.New("device credential is incomplete")
	}

	file, err := store.read()
	if errors.Is(err, os.ErrNotExist) {
		file = deviceCredentialFile{Version: deviceCredentialFileVersion}
	} else if err != nil {
		return err
	}

	now := store.now()
	credentials := make([]ApprovedDeviceCredential, 0, len(file.Credentials)+1)
	for _, existing := range file.Credentials {
		if !existing.ExpiresAt.After(now) || existing.Server == credential.Server && existing.LeaseID == credential.LeaseID {
			continue
		}
		credentials = append(credentials, existing)
	}
	file.Version = deviceCredentialFileVersion
	file.Credentials = append(credentials, credential)

	path, err := store.path()
	if err != nil {
		return err
	}
	directory := filepath.Dir(path)
	if err := os.MkdirAll(directory, 0o700); err != nil {
		return fmt.Errorf("create Crucible CLI configuration directory: %w", err)
	}
	if runtime.GOOS != "windows" {
		if err := os.Chmod(directory, 0o700); err != nil {
			return fmt.Errorf("secure Crucible CLI configuration directory: %w", err)
		}
	}

	temporary, err := os.CreateTemp(directory, ".device-credentials-*")
	if err != nil {
		return fmt.Errorf("create temporary device credential file: %w", err)
	}
	temporaryPath := temporary.Name()
	defer os.Remove(temporaryPath)
	if err := temporary.Chmod(0o600); err != nil {
		temporary.Close()

		return fmt.Errorf("secure temporary device credential file: %w", err)
	}
	encoder := json.NewEncoder(temporary)
	encoder.SetIndent("", "  ")
	if err := encoder.Encode(file); err != nil {
		temporary.Close()

		return fmt.Errorf("encode device credentials: %w", err)
	}
	if err := temporary.Sync(); err != nil {
		temporary.Close()

		return fmt.Errorf("sync device credentials: %w", err)
	}
	if err := temporary.Close(); err != nil {
		return fmt.Errorf("close device credentials: %w", err)
	}
	if err := os.Rename(temporaryPath, path); err != nil {
		return fmt.Errorf("save device credentials: %w", err)
	}
	if runtime.GOOS != "windows" {
		if err := os.Chmod(path, 0o600); err != nil {
			return fmt.Errorf("secure device credentials: %w", err)
		}
	}

	return nil
}

func (store fileDeviceCredentialStore) read() (deviceCredentialFile, error) {
	path, err := store.path()
	if err != nil {
		return deviceCredentialFile{}, err
	}
	info, err := os.Stat(path)
	if err != nil {
		return deviceCredentialFile{}, err
	}
	if runtime.GOOS != "windows" && info.Mode().Perm()&0o077 != 0 {
		return deviceCredentialFile{}, errors.New("device credential file permissions are too broad")
	}
	if info.Size() > maximumDeviceCredentialFileBytes {
		return deviceCredentialFile{}, errors.New("device credential file is too large")
	}

	input, err := os.Open(path)
	if err != nil {
		return deviceCredentialFile{}, err
	}
	defer input.Close()
	decoder := json.NewDecoder(io.LimitReader(input, maximumDeviceCredentialFileBytes+1))
	decoder.DisallowUnknownFields()
	var file deviceCredentialFile
	if err := decoder.Decode(&file); err != nil {
		return deviceCredentialFile{}, fmt.Errorf("decode device credentials: %w", err)
	}
	if file.Version != deviceCredentialFileVersion {
		return deviceCredentialFile{}, errors.New("unsupported device credential file version")
	}

	return file, nil
}

func (store fileDeviceCredentialStore) path() (string, error) {
	if store.Path != "" {
		return store.Path, nil
	}
	directory, err := os.UserConfigDir()
	if err != nil {
		return "", fmt.Errorf("locate user configuration directory: %w", err)
	}

	return filepath.Join(directory, "crucible", "device-credentials.json"), nil
}

func (store fileDeviceCredentialStore) now() time.Time {
	if store.Now != nil {
		return store.Now()
	}

	return time.Now()
}

func (credential ApprovedDeviceCredential) valid() bool {
	return credential.Server != "" && credential.LeaseID != "" && credential.DeviceID != "" && credential.AccessToken != "" && credential.ExpiresAt.After(time.Time{}) && (credential.Protocol == "postgresql" || credential.Protocol == "mysql")
}

func normalizeServer(server string) string {
	return strings.TrimRight(strings.TrimSpace(server), "/")
}
