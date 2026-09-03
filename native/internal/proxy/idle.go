package proxy

import (
	"net"
	"sync"
	"time"
)

type idleListener struct {
	net.Listener
	timeout     time.Duration
	mutex       sync.Mutex
	connections map[net.Conn]struct{}
}

func newIdleListener(listener net.Listener, timeout time.Duration) *idleListener {
	return &idleListener{Listener: listener, timeout: timeout, connections: make(map[net.Conn]struct{})}
}

func (listener *idleListener) Accept() (net.Conn, error) {
	connection, err := listener.Listener.Accept()
	if err != nil {
		return nil, err
	}

	listener.mutex.Lock()
	listener.connections[connection] = struct{}{}
	listener.mutex.Unlock()

	return &idleConnection{Conn: connection, timeout: listener.timeout, listener: listener}, nil
}

func (listener *idleListener) CloseConnections() {
	listener.mutex.Lock()
	connections := make([]net.Conn, 0, len(listener.connections))
	for connection := range listener.connections {
		connections = append(connections, connection)
	}
	listener.mutex.Unlock()
	for _, connection := range connections {
		_ = connection.Close()
	}
}

type idleConnection struct {
	net.Conn
	timeout  time.Duration
	listener *idleListener
}

func (connection *idleConnection) Close() error {
	connection.listener.mutex.Lock()
	delete(connection.listener.connections, connection.Conn)
	connection.listener.mutex.Unlock()

	return connection.Conn.Close()
}

func (connection *idleConnection) Read(buffer []byte) (int, error) {
	if err := connection.Conn.SetReadDeadline(time.Now().Add(connection.timeout)); err != nil {
		return 0, err
	}

	return connection.Conn.Read(buffer)
}

func (connection *idleConnection) Write(buffer []byte) (int, error) {
	if err := connection.Conn.SetWriteDeadline(time.Now().Add(connection.timeout)); err != nil {
		return 0, err
	}

	return connection.Conn.Write(buffer)
}
