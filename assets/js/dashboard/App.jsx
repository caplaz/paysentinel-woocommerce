import React, { useState, useEffect } from "react";
import GatewayHealthComponent from "./components/GatewayHealth";
import FailedTransactionsComponent from "./components/FailedTransactions";
import "./App.css";

/**
 * Main Payment Monitor Dashboard Application
 *
 * Displays real-time payment gateway health metrics and failed transactions
 * with auto-refresh capabilities
 */
function App() {
  const [refreshInterval, setRefreshInterval] = useState(30); // seconds
  const [lastRefresh, setLastRefresh] = useState(null);
  const [isLoading, setIsLoading] = useState(false);

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
  const handleManualRefresh = () => {
    setLastRefresh(new Date());
  };

  return (
    <div className='wc-payment-monitor-dashboard'>
      <div className='dashboard-header'>
        <h1>Payment Monitor Dashboard</h1>
        <div className='dashboard-controls'>
          <div className='refresh-control'>
            <label htmlFor='refresh-interval'>Auto-refresh interval:</label>
            <select
              id='refresh-interval'
              value={refreshInterval}
              onChange={handleRefreshIntervalChange}>
              <option value='5'>5 seconds</option>
              <option value='10'>10 seconds</option>
              <option value='30'>30 seconds</option>
              <option value='60'>1 minute</option>
              <option value='300'>5 minutes</option>
            </select>
          </div>
          <button
            className='refresh-button'
            onClick={handleManualRefresh}
            disabled={isLoading}>
            {isLoading ? "Refreshing..." : "Refresh Now"}
          </button>
          {lastRefresh && (
            <span className='last-refresh'>
              Last updated: {lastRefresh.toLocaleTimeString()}
            </span>
          )}
        </div>
      </div>

      <div className='dashboard-grid'>
        <div className='dashboard-section'>
          <GatewayHealthComponent
            refreshInterval={refreshInterval}
            onRefresh={handleManualRefresh}
            onLoadingChange={setIsLoading}
          />
        </div>

        <div className='dashboard-section'>
          <FailedTransactionsComponent
            refreshInterval={refreshInterval}
            onRefresh={handleManualRefresh}
            onLoadingChange={setIsLoading}
          />
        </div>
      </div>
    </div>
  );
}

export default App;
