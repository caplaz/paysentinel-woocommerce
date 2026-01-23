import React, { useState, useEffect, useCallback } from "react";
import GatewayHealthComponent from "./components/GatewayHealth";
import FailedTransactionsComponent from "./components/FailedTransactions";
import "./App.css";

/**
 * Main Payment Monitor Dashboard Application
 *
 * Displays real-time payment gateway health metrics and failed transactions
 * with auto-refresh capabilities and connection status monitoring
 */
function App() {
  const [refreshInterval, setRefreshInterval] = useState(30); // seconds
  const [lastRefresh, setLastRefresh] = useState(null);
  const [isLoading, setIsLoading] = useState(false);
  const [connectionStatus, setConnectionStatus] = useState("connected");
  const [refreshKey, setRefreshKey] = useState(0);

  /**
   * Handle refresh interval change
   */
  const handleRefreshIntervalChange = (e) => {
    const interval = parseInt(e.target.value);
    setRefreshInterval(Math.max(5, interval)); // minimum 5 seconds
  };

  /**
   * Force manual refresh
   */
  const handleManualRefresh = useCallback(() => {
    setRefreshKey((prev) => prev + 1);
    setLastRefresh(new Date());
  }, []);

  /**
   * Handle loading state changes from child components
   */
  const handleLoadingChange = useCallback((loading) => {
    setIsLoading(loading);
    if (!loading) {
      setLastRefresh(new Date());
      setConnectionStatus("connected");
    }
  }, []);

  /**
   * Handle connection errors from child components
   */
  const handleConnectionError = useCallback(() => {
    setConnectionStatus("error");
  }, []);

  /**
   * Get relative time for last update
   */
  const getRelativeTime = useCallback(() => {
    if (!lastRefresh) return null;

    const now = new Date();
    const diff = Math.floor((now - lastRefresh) / 1000);

    if (diff < 5) return "just now";
    if (diff < 60) return `${diff}s ago`;
    if (diff < 3600) return `${Math.floor(diff / 60)}m ago`;
    return lastRefresh.toLocaleTimeString();
  }, [lastRefresh]);

  /**
   * Update relative time display
   */
  const [relativeTime, setRelativeTime] = useState(null);
  useEffect(() => {
    const updateTime = () => setRelativeTime(getRelativeTime());
    updateTime();
    const interval = setInterval(updateTime, 5000);
    return () => clearInterval(interval);
  }, [getRelativeTime]);

  /**
   * Get connection status display
   */
  const getConnectionStatusText = () => {
    switch (connectionStatus) {
      case "connected":
        return "Connected";
      case "reconnecting":
        return "Reconnecting...";
      case "error":
        return "Connection Error";
      default:
        return "Unknown";
    }
  };

  return (
    <div className='wc-payment-monitor-dashboard'>
      <div className='dashboard-header'>
        <h1>Payment Monitor Dashboard</h1>
        <div className='dashboard-controls'>
          <div className={`connection-status ${connectionStatus}`}>
            {getConnectionStatusText()}
          </div>
          <div className='refresh-control'>
            <label htmlFor='refresh-interval'>Auto-refresh:</label>
            <select
              id='refresh-interval'
              value={refreshInterval}
              onChange={handleRefreshIntervalChange}>
              <option value='5'>5s</option>
              <option value='10'>10s</option>
              <option value='30'>30s</option>
              <option value='60'>1m</option>
              <option value='300'>5m</option>
            </select>
          </div>
          <button
            className='refresh-button'
            onClick={handleManualRefresh}
            disabled={isLoading}>
            {isLoading ? (
              <>
                <span className='spinner'></span>
                Refreshing...
              </>
            ) : (
              "Refresh Now"
            )}
          </button>
          {relativeTime && (
            <span className='last-refresh' title={lastRefresh?.toLocaleString()}>
              Updated {relativeTime}
            </span>
          )}
        </div>
      </div>

      <div className='dashboard-grid'>
        <div className='dashboard-section'>
          <GatewayHealthComponent
            key={`health-${refreshKey}`}
            refreshInterval={refreshInterval}
            onRefresh={handleManualRefresh}
            onLoadingChange={handleLoadingChange}
            onConnectionError={handleConnectionError}
          />
        </div>

        <div className='dashboard-section'>
          <FailedTransactionsComponent
            key={`transactions-${refreshKey}`}
            refreshInterval={refreshInterval}
            onRefresh={handleManualRefresh}
            onLoadingChange={handleLoadingChange}
            onConnectionError={handleConnectionError}
          />
        </div>
      </div>
    </div>
  );
}

export default App;
