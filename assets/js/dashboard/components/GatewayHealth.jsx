import React, { useState, useEffect, useCallback } from "react";

/**
 * Gateway Health Component
 *
 * Displays real-time health metrics for all payment gateways
 * including success rates, transaction counts, and status indicators
 */
function GatewayHealthComponent({
  refreshInterval,
  onRefresh,
  onLoadingChange,
}) {
  const [gateways, setGateways] = useState([]);
  const [isLoading, setIsLoading] = useState(false);
  const [error, setError] = useState(null);

  /**
   * Fetch gateway health data from REST API
   */
  const fetchGatewayHealth = useCallback(async () => {
    setIsLoading(true);
    onLoadingChange(true);
    setError(null);

    try {
      const response = await fetch(
        "/wp-json/wc-payment-monitor/v1/health/gateways",
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
  }, [onLoadingChange]);

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

  return (
    <div className='gateway-health-component'>
      <h2>Gateway Health Status</h2>

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
        {gateways.map((gateway) => (
          <div key={gateway.gateway_id} className='gateway-card'>
            <div className='gateway-header'>
              <h3>{gateway.gateway_name || gateway.gateway_id}</h3>
              <span
                className={`status-badge ${getStatusClass(
                  gateway.health_percentage
                )}`}>
                {getStatusText(gateway.health_percentage)}
              </span>
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
                <span className='stat-value'>{gateway.transaction_count}</span>
              </div>

              <div className='stat'>
                <span className='stat-label'>Failed (24h)</span>
                <span className='stat-value error'>
                  {gateway.failed_count_24h}
                </span>
              </div>
            </div>

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

            {gateway.last_checked && (
              <div className='gateway-footer'>
                <small>
                  Last checked:{" "}
                  {new Date(gateway.last_checked).toLocaleTimeString()}
                </small>
              </div>
            )}
          </div>
        ))}
      </div>
    </div>
  );
}

export default GatewayHealthComponent;
