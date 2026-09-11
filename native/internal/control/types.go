package control

// DeviceAuthorizationRequest is the limited set of CLI metadata accepted by
// the Laravel device authorization workflow.
type DeviceAuthorizationRequest struct {
	LeaseID         string `json:"lease_id"`
	CLIVersion      string `json:"cli_version,omitempty"`
	OperatingSystem string `json:"operating_system,omitempty"`
	Architecture    string `json:"architecture,omitempty"`
	DeviceLabel     string `json:"device_label,omitempty"`
}

type DeviceAuthorizationResponse struct {
	DeviceCode              string `json:"device_code"`
	UserCode                string `json:"user_code"`
	VerificationURI         string `json:"verification_uri"`
	VerificationURIComplete string `json:"verification_uri_complete"`
	ExpiresIn               int    `json:"expires_in"`
	Interval                int    `json:"interval"`
	Protocol                string `json:"protocol"`
}

type DeviceTokenRequest struct {
	DeviceCode string `json:"device_code"`
}

type DeviceTokenResponse struct {
	AccessToken string `json:"access_token"`
	TokenType   string `json:"token_type"`
	ExpiresIn   int    `json:"expires_in"`
	Error       string `json:"error"`
	Interval    int    `json:"interval"`
	DeviceID    string `json:"device_authorization_id"`
}

type LeaseHeartbeatRequest struct {
	LeaseID    string `json:"lease_id"`
	DeviceID   string `json:"device_authorization_id"`
	BearerHash string `json:"bearer_hash"`
}

type LeaseHeartbeatResponse struct {
	Status   string `json:"status"`
	Continue bool   `json:"continue"`
}

type TunnelAuthorizationRequest struct {
	LeaseID       string `json:"lease_id"`
	DeviceID      string `json:"device_authorization_id"`
	ConnectionID  string `json:"proxy_connection_id"`
	Protocol      string `json:"protocol"`
	ProxyID       string `json:"proxy_instance_id"`
	BearerHash    string `json:"bearer_hash"`
	RemoteAddress string `json:"remote_address,omitempty"`
}

type TunnelAuthorizationResponse struct {
	AuthAttemptID                string `json:"auth_attempt_id"`
	LeaseID                      string `json:"lease_id"`
	ProxyConnectionID            string `json:"proxy_connection_id"`
	Protocol                     string `json:"protocol"`
	CredentialVersion            int    `json:"credential_version"`
	ProtocolAuthenticationSecret string `json:"protocol_authentication_secret"`
}

type ConnectionAuthorizationRequest struct {
	AuthAttemptID     string `json:"auth_attempt_id"`
	ProxyID           string `json:"proxy_instance_id"`
	SyntheticUsername string `json:"synthetic_username"`
	SyntheticPassword string `json:"synthetic_password"`
	Protocol          string `json:"protocol"`
}

type UpstreamConfiguration struct {
	Host                 string `json:"host"`
	Port                 int    `json:"port"`
	Database             string `json:"database"`
	Username             string `json:"username"`
	Password             string `json:"password"`
	TLSMode              string `json:"tls_mode"`
	TLSCACertificate     string `json:"tls_ca_certificate"`
	TLSClientCertificate string `json:"tls_client_certificate"`
	TLSClientKey         string `json:"tls_client_key"`
}

type ConnectionAuthorizationResponse struct {
	ConnectionID      string `json:"connection_id"`
	LeaseID           string `json:"lease_id"`
	UserID            string `json:"user_id"`
	Protocol          string `json:"protocol"`
	CredentialVersion int    `json:"credential_version"`
	ReadOnly          bool   `json:"read_only"`
}

type ConnectionUpstreamMaterialResponse struct {
	ConnectionID string                `json:"connection_id"`
	Upstream     UpstreamConfiguration `json:"upstream"`
}

type ConnectionLifecycleRequest struct {
	ProxyID string `json:"proxy_instance_id"`
	Reason  string `json:"reason,omitempty"`
}

type ConnectionAuthenticatedRequest struct {
	ProxyID           string `json:"proxy_instance_id"`
	ClientApplication string `json:"client_application,omitempty"`
	ClientVersion     string `json:"client_version,omitempty"`
}

type ConnectionTrafficRequest struct {
	ProxyID           string `json:"proxy_instance_id"`
	ProxyConnectionID string `json:"proxy_connection_id"`
	BytesReceived     int64  `json:"bytes_received"`
	BytesSent         int64  `json:"bytes_sent"`
}

type ConnectionHeartbeatResponse struct {
	Status   string `json:"status"`
	Continue bool   `json:"continue"`
}

type StatementRequest struct {
	ProxyID         string `json:"proxy_instance_id"`
	SQL             string `json:"sql"`
	ProtocolCommand string `json:"protocol_command"`
	ParameterCount  int    `json:"parameter_count"`
}

type StatementDecision struct {
	Allowed        bool   `json:"allowed"`
	Code           string `json:"code"`
	Message        string `json:"message"`
	SessionCommand bool   `json:"session_command"`
}

type StatementAuthorization struct {
	StatementID int `json:"statement_id"`
}

type StatementOutcome struct {
	ProxyID      string `json:"proxy_instance_id"`
	Succeeded    bool   `json:"succeeded"`
	RowCount     int64  `json:"row_count,omitempty"`
	DurationMS   int64  `json:"duration_ms,omitempty"`
	ErrorMessage string `json:"error_message,omitempty"`
}
