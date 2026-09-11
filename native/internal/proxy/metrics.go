package proxy

import (
	"net/http"

	"github.com/prometheus/client_golang/prometheus"
	"github.com/prometheus/client_golang/prometheus/collectors"
	"github.com/prometheus/client_golang/prometheus/promhttp"
)

type Metrics struct {
	connections prometheus.Gauge
	registry    *prometheus.Registry
}

func NewMetrics() *Metrics {
	registry := prometheus.NewRegistry()
	connections := prometheus.NewGauge(prometheus.GaugeOpts{
		Namespace: "crucible_native_proxy",
		Name:      "active_connections",
		Help:      "Active native proxy connections.",
	})
	registry.MustRegister(connections)
	registry.MustRegister(collectors.NewGoCollector(), collectors.NewProcessCollector(collectors.ProcessCollectorOpts{}))

	return &Metrics{connections: connections, registry: registry}
}

func (metrics *Metrics) SetConnections(count int) {
	metrics.connections.Set(float64(count))
}

func (metrics *Metrics) Handler() http.Handler {
	return promhttp.HandlerFor(metrics.registry, promhttp.HandlerOpts{})
}
