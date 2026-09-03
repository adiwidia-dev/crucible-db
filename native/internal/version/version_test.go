package version

import "testing"

func TestIsOlderComparesStableSemanticVersionsOnly(t *testing.T) {
	for _, test := range []struct {
		current string
		latest  string
		want    bool
	}{
		{current: "0.1.0", latest: "0.2.0", want: true},
		{current: "v1.3.0", latest: "1.2.9", want: false},
		{current: "1.2.3", latest: "1.2.3", want: false},
		{current: "dev", latest: "1.2.3", want: false},
		{current: "1.2.3-rc.1", latest: "1.2.3", want: false},
	} {
		if got := IsOlder(test.current, test.latest); got != test.want {
			t.Fatalf("IsOlder(%q, %q) = %t, want %t", test.current, test.latest, got, test.want)
		}
	}
}
