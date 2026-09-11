package mysql

import "testing"

func FuzzCommandPacket(f *testing.F) {
	f.Add([]byte("SELECT ?"))
	f.Add([]byte("LOAD DATA LOCAL INFILE 'x'"))
	f.Fuzz(func(t *testing.T, packet []byte) {
		query := string(packet)
		_ = countMySQLParameters(query)
		_ = isUnsafeMySQLQuery(query)
		_ = isShowDatabases(query)
	})
}
