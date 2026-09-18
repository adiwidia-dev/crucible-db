package cli

import (
	"context"
	"crypto/rand"
	"crypto/tls"
	"crypto/x509"
	"encoding/hex"
	"errors"
	"fmt"
	"io"
	"net"
	"net/http"
	"net/url"
	"os"
	"strings"
	"time"

	commandexec "github.com/adiwidia-dev/crucible-db/native/internal/command"
	"github.com/adiwidia-dev/crucible-db/native/internal/control"
	"github.com/adiwidia-dev/crucible-db/native/internal/tunnel"
	"github.com/adiwidia-dev/crucible-db/native/internal/version"
	"github.com/coder/websocket"
	"github.com/spf13/cobra"
)

type Dependencies struct {
	DeviceClient      DeviceClient
	LeaseClient       LeaseClient
	DeviceCredentials DeviceCredentialStore
	DiscoveryClient   DiscoveryClient
	OpenBrowser       func(string) error
	Sleep             func(context.Context, time.Duration) error
	ListenLoopback    func(string) (net.Listener, error)
	ServeLoopback     func(context.Context, net.Listener, tunnel.Opener) error
	HeartbeatInterval time.Duration
	Stdout            io.Writer
	Stderr            io.Writer
	AllowInsecureHTTP bool
}

func NewRootCommand(dependencies Dependencies) *cobra.Command {
	if dependencies.Stdout == nil {
		dependencies.Stdout = io.Discard
	}
	if dependencies.Stderr == nil {
		dependencies.Stderr = io.Discard
	}
	if dependencies.DeviceClient == nil {
		dependencies.DeviceClient = HTTPClient{}
	}
	if dependencies.LeaseClient == nil {
		dependencies.LeaseClient = HTTPClient{}
	}
	if dependencies.DeviceCredentials == nil {
		dependencies.DeviceCredentials = newFileDeviceCredentialStore()
	}
	if dependencies.DiscoveryClient == nil {
		dependencies.DiscoveryClient = HTTPClient{}
	}
	if dependencies.OpenBrowser == nil {
		dependencies.OpenBrowser = openBrowser
	}
	if dependencies.Sleep == nil {
		dependencies.Sleep = sleep
	}
	if dependencies.ListenLoopback == nil {
		dependencies.ListenLoopback = tunnel.ListenLoopback
	}
	if dependencies.ServeLoopback == nil {
		dependencies.ServeLoopback = tunnel.ServeLoopback
	}
	if dependencies.HeartbeatInterval <= 0 {
		dependencies.HeartbeatInterval = 2 * time.Second
	}

	root := &cobra.Command{
		Use:           "crucible",
		Short:         "Connect database clients through governed Crucible access",
		SilenceUsage:  true,
		SilenceErrors: true,
		Version:       version.Version,
	}
	root.SetOut(dependencies.Stdout)
	root.SetErr(dependencies.Stderr)
	root.SetVersionTemplate("{{.Version}}\n")
	root.AddCommand(newConnectCommand(dependencies))
	root.AddCommand(newCompletionCommand())
	root.AddCommand(newVersionCommand())

	return root
}

func newConnectCommand(dependencies Dependencies) *cobra.Command {
	var server string
	var leaseID string
	var noBrowser bool
	var jsonOutput bool
	var allowInsecureHTTP bool
	var listenAddress string
	var caFile string
	var authorizationTimeout time.Duration

	command := &cobra.Command{
		Use:   "connect",
		Short: "Authorize native database-client access",
		RunE: func(command *cobra.Command, args []string) error {
			if err := validateServer(server, dependencies.AllowInsecureHTTP || allowInsecureHTTP); err != nil {
				return commandexec.Wrap(commandexec.ExitUsage, err)
			}
			if authorizationTimeout <= 0 {
				return commandexec.Wrap(commandexec.ExitUsage, errors.New("--authorization-timeout must be greater than zero"))
			}

			deviceClient := dependencies.DeviceClient
			leaseClient := dependencies.LeaseClient
			discoveryClient := dependencies.DiscoveryClient
			if caFile != "" {
				httpClient, err := newCAHTTPClient(caFile)
				if err != nil {
					return commandexec.Wrap(commandexec.ExitUsage, err)
				}
				deviceClient = HTTPClient{Client: httpClient}
				leaseClient = HTTPClient{Client: httpClient}
				discoveryClient = HTTPClient{Client: httpClient}
			}

			discovery, err := discoveryClient.Discover(command.Context(), server)
			if err != nil {
				return commandexec.Wrap(commandexec.ExitNetwork, err)
			}
			output := output{writer: command.OutOrStdout(), json: jsonOutput}
			if version.IsOlder(version.Version, discovery.LatestCLIVersion) {
				output.event("update_available", map[string]string{"latest_version": discovery.LatestCLIVersion})
			}
			approvedDevice, remembered, loadErr := dependencies.DeviceCredentials.Load(server, leaseID)
			protocol := approvedDevice.Protocol
			accessExpiresAt := approvedDevice.ExpiresAt
			token := control.DeviceTokenResponse{
				AccessToken: approvedDevice.AccessToken,
				DeviceID:    approvedDevice.DeviceID,
			}
			if remembered {
				output.event("authorization_reused", map[string]string{})
			} else {
				if loadErr != nil {
					output.event("authorization_not_loaded", map[string]string{})
				}
				flow := DeviceFlow{Client: deviceClient, Sleep: dependencies.Sleep}
				authorizationContext, cancelAuthorization := context.WithTimeout(command.Context(), authorizationTimeout)
				defer cancelAuthorization()
				token, err = flow.Authorize(authorizationContext, discovery.DeviceAuthorizationEndpoint, discovery.TokenEndpoint, defaultDeviceInput(leaseID), func(challenge control.DeviceAuthorizationResponse) {
					protocol = challenge.Protocol
					output.event("authorization_required", map[string]string{
						"verification_uri": challenge.VerificationURI,
						"user_code":        challenge.UserCode,
					})
					if !noBrowser {
						_ = dependencies.OpenBrowser(challenge.VerificationURI)
					}
				})
				if err != nil {
					if errors.Is(err, ErrDeviceAuthorizationDenied) || errors.Is(err, ErrDeviceAuthorizationExpired) || errors.Is(err, context.DeadlineExceeded) {
						return commandexec.Wrap(commandexec.ExitAuthorization, err)
					}

					return commandexec.Wrap(commandexec.ExitNetwork, err)
				}
				if token.ExpiresIn <= 0 {
					return commandexec.Wrap(commandexec.ExitAuthorization, errors.New("native client access has expired"))
				}
				accessExpiresAt = time.Now().Add(time.Duration(token.ExpiresIn) * time.Second)
				if err := dependencies.DeviceCredentials.Save(ApprovedDeviceCredential{
					Server:      server,
					LeaseID:     leaseID,
					Protocol:    protocol,
					DeviceID:    token.DeviceID,
					AccessToken: token.AccessToken,
					ExpiresAt:   accessExpiresAt,
				}); err != nil {
					output.event("authorization_not_saved", map[string]string{})
				}
			}
			if protocol != "postgresql" && protocol != "mysql" || token.DeviceID == "" {
				return commandexec.Wrap(commandexec.ExitNetwork, errors.New("native client authorization returned an unsupported protocol"))
			}
			if !accessExpiresAt.After(time.Now()) {
				return commandexec.Wrap(commandexec.ExitAuthorization, errors.New("native client access has expired"))
			}
			accessContext, cancelAccess := context.WithDeadline(command.Context(), accessExpiresAt)
			defer cancelAccess()
			if listenAddress == "" {
				if protocol == "postgresql" {
					listenAddress = "127.0.0.1:5432"
				} else {
					listenAddress = "127.0.0.1:3306"
				}
			}
			listener, err := dependencies.ListenLoopback(listenAddress)
			if err != nil {
				return commandexec.Wrap(commandexec.ExitRuntime, fmt.Errorf("start local %s listener: %w", protocol, err))
			}
			defer listener.Close()
			tunnelURL, err := ExpandTunnelEndpoint(discovery.TunnelEndpoint, leaseID)
			if err != nil {
				return commandexec.Wrap(commandexec.ExitNetwork, err)
			}
			heartbeatURL, err := ExpandLeaseHeartbeatEndpoint(discovery.LeaseHeartbeatEndpoint, leaseID)
			if err != nil {
				return commandexec.Wrap(commandexec.ExitNetwork, err)
			}
			output.event("listening", map[string]string{"address": listener.Addr().String(), "protocol": protocol})
			output.event("authorized", map[string]string{})
			heartbeatResult := make(chan error, 1)
			go func() {
				heartbeatErr := monitorLease(accessContext, leaseClient, heartbeatURL, token.AccessToken, token.DeviceID, dependencies.HeartbeatInterval)
				if heartbeatErr != nil {
					cancelAccess()
				}
				heartbeatResult <- heartbeatErr
			}()

			serveErr := dependencies.ServeLoopback(accessContext, listener, func(ctx context.Context, local net.Conn) (*websocket.Conn, error) {
				return tunnel.Dial(ctx, tunnelURL, tunnel.ClientConnection{
					LeaseID:          leaseID,
					DeviceID:         token.DeviceID,
					ConnectionID:     newConnectionID(),
					BearerToken:      token.AccessToken,
					DatabaseProtocol: protocol,
				})
			})
			cancelAccess()
			heartbeatErr := <-heartbeatResult
			if errors.Is(heartbeatErr, ErrLeaseEnded) {
				output.event("access_ended", map[string]string{})

				return nil
			}
			if heartbeatErr != nil {
				return commandexec.Wrap(commandexec.ExitNetwork, heartbeatErr)
			}
			if serveErr != nil && command.Context().Err() == nil && !errors.Is(accessContext.Err(), context.DeadlineExceeded) {
				return commandexec.Wrap(commandexec.ExitRuntime, serveErr)
			}
			if errors.Is(accessContext.Err(), context.DeadlineExceeded) {
				output.event("access_expired", map[string]string{})
			}

			return nil
		},
	}
	command.Flags().StringVar(&server, "server", "", "Crucible server URL")
	command.Flags().StringVar(&leaseID, "lease", "", "Native client lease ID")
	command.Flags().BoolVar(&noBrowser, "no-browser", false, "Do not open a browser for approval")
	command.Flags().BoolVar(&jsonOutput, "json", false, "Write machine-readable progress events")
	command.Flags().StringVar(&listenAddress, "listen", "", "Loopback address for the local database listener")
	command.Flags().StringVar(&caFile, "ca-file", "", "PEM file for the Crucible server certificate authority")
	command.Flags().DurationVar(&authorizationTimeout, "authorization-timeout", 6*time.Minute, "Maximum time to wait for browser authorization")
	command.Flags().BoolVar(&allowInsecureHTTP, "allow-insecure-http", false, "Allow HTTP only for local test environments")
	_ = command.Flags().MarkHidden("allow-insecure-http")
	_ = command.MarkFlagRequired("server")
	_ = command.MarkFlagRequired("lease")

	return command
}

func newCAHTTPClient(caFile string) (*http.Client, error) {
	certificate, err := os.ReadFile(caFile)
	if err != nil {
		return nil, fmt.Errorf("read --ca-file: %w", err)
	}
	pool, err := x509.SystemCertPool()
	if err != nil || pool == nil {
		pool = x509.NewCertPool()
	}
	if !pool.AppendCertsFromPEM(certificate) {
		return nil, errors.New("--ca-file does not contain a valid PEM certificate")
	}
	transport, ok := http.DefaultTransport.(*http.Transport)
	if !ok {
		return nil, errors.New("default HTTP transport is not available")
	}
	configuredTransport := transport.Clone()
	configuredTransport.TLSClientConfig = &tls.Config{MinVersion: tls.VersionTLS12, RootCAs: pool}

	return &http.Client{Timeout: 10 * time.Second, Transport: configuredTransport}, nil
}

func newVersionCommand() *cobra.Command {
	return &cobra.Command{
		Use:   "version",
		Short: "Print the Crucible CLI version",
		Run: func(command *cobra.Command, _ []string) {
			_, _ = fmt.Fprintln(command.OutOrStdout(), version.Version)
		},
	}
}

func validateServer(rawURL string, allowInsecureHTTP bool) error {
	server, err := url.Parse(rawURL)
	if err != nil || server.Host == "" || (server.Scheme != "https" && !(allowInsecureHTTP && server.Scheme == "http")) {
		return errors.New("--server must be an HTTPS URL")
	}
	if strings.TrimSpace(server.RawQuery) != "" || strings.TrimSpace(server.Fragment) != "" {
		return errors.New("--server must not include a query string or fragment")
	}

	return nil
}

func newCompletionCommand() *cobra.Command {
	return &cobra.Command{
		Use:       "completion [bash|zsh|fish|powershell]",
		Short:     "Generate a shell completion script",
		Args:      cobra.MatchAll(cobra.ExactArgs(1), cobra.OnlyValidArgs),
		ValidArgs: []string{"bash", "zsh", "fish", "powershell"},
		RunE: func(command *cobra.Command, args []string) error {
			switch args[0] {
			case "bash":
				return command.Root().GenBashCompletion(command.OutOrStdout())
			case "zsh":
				return command.Root().GenZshCompletion(command.OutOrStdout())
			case "fish":
				return command.Root().GenFishCompletion(command.OutOrStdout(), true)
			case "powershell":
				return command.Root().GenPowerShellCompletionWithDesc(command.OutOrStdout())
			default:
				return fmt.Errorf("unsupported shell")
			}
		},
	}
}

func sleep(ctx context.Context, duration time.Duration) error {
	timer := time.NewTimer(duration)
	defer timer.Stop()
	select {
	case <-ctx.Done():
		return ctx.Err()
	case <-timer.C:
		return nil
	}
}

func newConnectionID() string {
	bytes := make([]byte, 24)
	if _, err := rand.Read(bytes); err != nil {
		return fmt.Sprintf("connection-%d", time.Now().UnixNano())
	}

	return hex.EncodeToString(bytes)
}
