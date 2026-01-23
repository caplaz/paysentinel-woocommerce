import React, { useState, useEffect, useCallback } from "react";
import HealthTrendChart from "./charts/HealthTrendChart";

/**
 * Gateway Health Component
 *
 * Displays real-time health metrics for all payment gateways
 * including success rates, transaction counts, status indicators,
 * and historical trend charts
 */
function GatewayHealthComponent({
  refreshInterval,
  onRefresh,
  onLoadingChange,
}) {
  const [gateways, setGateways] = useState([]);
  const [isLoading, setIsLoading] = useState(false);
  const [error, setError] = useState(null);
  const [timePeriod, setTimePeriod] = useState("24h");
  const [expandedGateway, setExpandedGateway] = useState(null);

  /**
   * Fetch gateway health data from REST API
   */
  const fetchGatewayHealth = useCallback(async () => {
    setIsLoading(true);
    onLoadingChange(true);
    setError(null);

    try {
      const response = await fetch(
        `/wp-json/wc-payment-monitor/v1/health/gateways?period=${timePeriod}`,
        {
          method: "GET",
          headers: {
            "Content-Type": "application/json",
            "X-WP-Nonce": window.wcPaymentMonitor?.nonce || "",
          },
        }
      );

      if (!response.ok) {
        throw new Error(`HTTP error! status: ${response.status}`);
      }

      const data = await response.json();

      if (data.success && data.data) {
        setGateways(data.data);
      } else {
        setError(data.message || "Failed to fetch gateway health");
      }
    } catch (err) {
      setError(err.message || "Failed to fetch gateway health");
      console.error("Gateway health fetch error:", err);
    } finally {
      setIsLoading(false);
      onLoadingChange(false);
    }
  }, [onLoadingChange, timePeriod]);

  /**
   * Auto-refresh effect
   */
  useEffect(() => {
    // Initial fetch
    fetchGatewayHealth();

    // Set up auto-refresh interval
    const intervalId = setInterval(() => {
      fetchGatewayHealth();
    }, refreshInterval * 1000);

    return () => clearInterval(intervalId);
  }, [refreshInterval, fetchGatewayHealth]);

  /**
   * Get status badge class based on health percentage
   */
  const getStatusClass = (health) => {
    if (health >= 95) return "status-excellent";
    if (health >= 90) return "status-good";
    if (health >= 75) return "status-warning";
    return "status-critical";
  };

  /**
   * Get status text
   */
  const getStatusText = (health) => {
    if (health >= 95) return "Excellent";
    if (health >= 90) return "Good";
    if (health >= 75) return "Warning";
    return "Critical";
  };

  /**
   * Get connectivity status badge class and text
   */
  const getConnectivityClass = (status) => {
    if (status === "online") return "connectivity-online";
    if (status === "offline") return "connectivity-offline";
    return "connectivity-unconfigured";
  };

  /**
   * Get connectivity status text
   */
  const getConnectivityText = (status) => {
    if (status === "online") return "Connected";
    if (status === "offline") return "Disconnected";
    return "Not Configured";
  };

  /**
   * Get time period label
   */
  const getTimePeriodLabel = () => {
    const periods = {
      "24h": "Last 24 Hours",
      "7d": "Last 7 Days",
      "30d": "Last 30 Days",
    };
    return periods[timePeriod] || "Last 24 Hours";
  };

  return (
    <div className='gateway-health-component'>
      <div className='section-header'>
        <div>
          <h2>Gateway Health Status</h2>
          <p className='section-subtitle'>
            Real-time health metrics for all payment gateways
          </p>
        </div>
        <div className='time-period-selector'>
          <label htmlFor='time-period'>View:</label>
          <select
            id='time-period'
            value={timePeriod}
            onChange={(e) => setTimePeriod(e.target.value)}>
            <option value='24h'>Last 24 Hours</option>
            <option value='7d'>Last 7 Days</option>
            <option value='30d'>Last 30 Days</option>
          </select>
        </div>
      </div>

      <div className='trend-info'>
        <small>Showing {getTimePeriodLabel()}</small>
      </div>

      {error && <div className='error-message'>{error}</div>}

      {isLoading && gateways.length === 0 && (
        <div className='loading-state'>Loading gateway health data...</div>
      )}

      {gateways.length === 0 && !isLoading && !error && (
        <div className='empty-state'>
          No gateway data available yet. Please check back soon.
        </div>
      )}

      <div className='gateways-grid'>
        {gateways.map((gateway) => {
          const isExpanded = expandedGateway === gateway.gateway_id;
          const trendData = gateway.trend_data || [];

          return (
            <div
              key={gateway.gateway_id}
              className={`gateway-card ${isExpanded ? "expanded" : ""}`}>
              <div className='gateway-header'>
                <h3>{gateway.gateway_name || gateway.gateway_id}</h3>
                <div className='gateway-badges'>
                  <span
                    className={`status-badge ${getStatusClass(
                      gateway.health_percentage
                    )}`}
                    title='Transaction-based health status'>
                    {getStatusText(gateway.health_percentage)}
                  </span>
                  {gateway.connectivity_status && (
                    <span
                      className={`connectivity-badge ${getConnectivityClass(
                        gateway.connectivity_status
                      )}`}
                      title={gateway.connectivity_message || "Connectivity status"}>
                      {getConnectivityText(gateway.connectivity_status)}
                    </span>
                  )}
                </div>
              </div>

              <div className='gateway-stats'>
                <div className='stat'>
                  <span className='stat-label'>Health Score</span>
                  <span className='stat-value'>
                    {gateway.health_percentage.toFixed(1)}%
                  </span>
                </div>

                <div className='stat'>
                  <span className='stat-label'>Success Rate (24h)</span>
                  <span className='stat-value'>
                    {gateway.success_rate_24h.toFixed(1)}%
                  </span>
                </div>

                <div className='stat'>
                  <span className='stat-label'>Total Transactions</span>
                  <span className='stat-value'>
                    {gateway.transaction_count}
                  </span>
                </div>

                <div className='stat'>
                  <span className='stat-label'>Failed (24h)</span>
                  <span className='stat-value error'>
                    {gateway.failed_count_24h}
                  </span>
                </div>
              </div>

              {
                gateway.connectivity_status && (
                  <div className='connectivity-info'>
                    <div className='connectivity-row'>
                      <span className='connectivity-label'>API Status:</span>
                      <span className={`connectivity-value ${getConnectivityClass(gateway.connectivity_status)}`}>
                        {getConnectivityText(gateway.connectivity_status)}
                      </span>
                    </div>
                    {gateway.connectivity_response_time_ms !== null && (
                      <div className='connectivity-row'>
                        <span className='connectivity-label'>Response Time:</span>
                        <span className='connectivity-value'>
                          {gateway.connectivity_response_time_ms.toFixed(0)}ms
                        </span>
                      </div>
                    )}
                    {gateway.connectivity_checked_at && (
                      <div className='connectivity-row'>
                        <span className='connectivity-label'>Last Checked:</span>
                        <span className='connectivity-value'>
                          {new Date(gateway.connectivity_checked_at).toLocaleTimeString()}
                        </span>
                      </div>
                    )}
                    {gateway.connectivity_message && (
                      <div className='connectivity-message'>
                        <small>{gateway.connectivity_message}</small>
                      </div>
                    )}
                  </div>
                )
              }

              <div className='gateway-chart'>
                <div className='progress-bar'>
                  <div
                    className={`progress-fill ${getStatusClass(
                      gateway.health_percentage
                    )}`}
                    style={{ width: `${gateway.health_percentage}%` }}
                  />
                </div>
              </div>

              {
                trendData.length > 0 && (
                  <div className='trend-section'>
                    <button
                      className='expand-trend-btn'
                      onClick={() =>
                        setExpandedGateway(isExpanded ? null : gateway.gateway_id)
                      }>
                      {isExpanded ? "Hide" : "Show"} Historical Trend
                    </button>

                    {isExpanded && (
                      <div className='trend-chart'>
                        <div className='trend-header'>
                          Historical Trend - {getTimePeriodLabel()}
                        </div>
                        <div className='chart-container'>
                          <HealthTrendChart
                            data={trendData}
                            period={timePeriod}
                            gatewayName={gateway.gateway_name || gateway.gateway_id}
                          />
                        </div>
                        <div className='trend-stats'>
                          <div className='trend-stat'>
                            <span>
                              Highest:{" "}
                              {Math.max(
                                ...trendData.map((p) => p.health_score)
                              ).toFixed(1)}
                              %
                            </span>
                          </div>
                          <div className='trend-stat'>
                            <span>
                              Average:{" "}
                              {(
                                trendData.reduce(
                                  (sum, p) => sum + p.health_score,
                                  0
                                ) / trendData.length
                              ).toFixed(1)}
                              %
                            </span>
                          </div>
                          <div className='trend-stat'>
                            <span>
                              Lowest:{" "}
                              {Math.min(
                                ...trendData.map((p) => p.health_score)
                              ).toFixed(1)}
                              %
                            </span>
                          </div>
                        </div>
                      </div>
                    )}
                  </div>
                )
              }

              {
                gateway.last_checked && (
                  <div className='gateway-footer'>
                    <small>
                      Last checked:{" "}
                      {new Date(gateway.last_checked).toLocaleTimeString()}
                    </small>
                  </div>
                )
              }
            </div>
          );
        })}
      </div>
    </div >
  );
}

export default GatewayHealthComponent;
