import React, { useState, useEffect, useCallback } from "react";

/**
 * Failed Transactions Component
 *
 * Displays a list of failed transactions with drill-down capability
 * to view detailed transaction information and history
 */
function FailedTransactionsComponent({
  refreshInterval,
  onRefresh,
  onLoadingChange,
}) {
  const [transactions, setTransactions] = useState([]);
  const [isLoading, setIsLoading] = useState(false);
  const [error, setError] = useState(null);
  const [expandedTransactionId, setExpandedTransactionId] = useState(null);
  const [filterGateway, setFilterGateway] = useState("");
  const [filterStatus, setFilterStatus] = useState("failed");

  /**
   * Fetch failed transactions from REST API
   */
  const fetchFailedTransactions = useCallback(async () => {
    setIsLoading(true);
    onLoadingChange(true);
    setError(null);

    try {
      // Build query parameters
      const params = new URLSearchParams({
        per_page: 50,
        status: filterStatus,
      });

      if (filterGateway) {
        params.append("gateway_id", filterGateway);
      }

      const response = await fetch(
        `/wp-json/wc-payment-monitor/v1/transactions?${params}`,
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
        setTransactions(data.data);
      } else {
        setError(data.message || "Failed to fetch transactions");
      }
    } catch (err) {
      setError(err.message || "Failed to fetch transactions");
      console.error("Transaction fetch error:", err);
    } finally {
      setIsLoading(false);
      onLoadingChange(false);
    }
  }, [filterGateway, filterStatus, onLoadingChange]);

  /**
   * Auto-refresh effect
   */
  useEffect(() => {
    // Initial fetch
    fetchFailedTransactions();

    // Set up auto-refresh interval
    const intervalId = setInterval(() => {
      fetchFailedTransactions();
    }, refreshInterval * 1000);

    return () => clearInterval(intervalId);
  }, [refreshInterval, fetchFailedTransactions]);

  /**
   * Toggle transaction details expansion
   */
  const toggleTransactionDetails = (transactionId) => {
    setExpandedTransactionId(
      expandedTransactionId === transactionId ? null : transactionId
    );
  };

  /**
   * Get unique gateways for filter dropdown
   */
  const getUniqueGateways = () => {
    const gateways = new Set(transactions.map((t) => t.gateway_id));
    return Array.from(gateways).sort();
  };

  /**
   * Format currency values
   */
  const formatCurrency = (value) => {
    return new Intl.NumberFormat("en-US", {
      style: "currency",
      currency: "USD",
    }).format(value);
  };

  /**
   * Format date and time
   */
  const formatDateTime = (dateString) => {
    try {
      return new Date(dateString).toLocaleString();
    } catch {
      return dateString;
    }
  };

  return (
    <div className='failed-transactions-component'>
      <h2>Failed Transactions</h2>

      <div className='transaction-filters'>
        <div className='filter-group'>
          <label htmlFor='gateway-filter'>Filter by Gateway:</label>
          <select
            id='gateway-filter'
            value={filterGateway}
            onChange={(e) => setFilterGateway(e.target.value)}>
            <option value=''>All Gateways</option>
            {getUniqueGateways().map((gateway) => (
              <option key={gateway} value={gateway}>
                {gateway}
              </option>
            ))}
          </select>
        </div>

        <div className='filter-group'>
          <label htmlFor='status-filter'>Status:</label>
          <select
            id='status-filter'
            value={filterStatus}
            onChange={(e) => setFilterStatus(e.target.value)}>
            <option value='failed'>Failed</option>
            <option value='pending'>Pending</option>
            <option value=''>All</option>
          </select>
        </div>
      </div>

      {error && <div className='error-message'>{error}</div>}

      {isLoading && transactions.length === 0 && (
        <div className='loading-state'>Loading transactions...</div>
      )}

      {transactions.length === 0 && !isLoading && !error && (
        <div className='empty-state'>
          No {filterStatus || "transactions"} found. Great job!
        </div>
      )}

      <div className='transactions-table'>
        <table>
          <thead>
            <tr>
              <th>Transaction ID</th>
              <th>Gateway</th>
              <th>Amount</th>
              <th>Status</th>
              <th>Date</th>
              <th>Actions</th>
            </tr>
          </thead>
          <tbody>
            {transactions.map((transaction) => (
              <React.Fragment key={transaction.transaction_id}>
                <tr
                  className={`transaction-row ${transaction.status}`}
                  onClick={() =>
                    toggleTransactionDetails(transaction.transaction_id)
                  }>
                  <td className='transaction-id'>
                    {transaction.transaction_id}
                  </td>
                  <td>{transaction.gateway_id}</td>
                  <td className='amount'>
                    {formatCurrency(transaction.amount)}
                  </td>
                  <td>
                    <span
                      className={`status-badge status-${transaction.status}`}>
                      {transaction.status.charAt(0).toUpperCase() +
                        transaction.status.slice(1)}
                    </span>
                  </td>
                  <td className='date'>
                    {formatDateTime(transaction.created_at)}
                  </td>
                  <td>
                    <button
                      className='expand-button'
                      onClick={(e) => {
                        e.stopPropagation();
                        toggleTransactionDetails(transaction.transaction_id);
                      }}>
                      {expandedTransactionId === transaction.transaction_id
                        ? "Hide"
                        : "Details"}
                    </button>
                  </td>
                </tr>

                {expandedTransactionId === transaction.transaction_id && (
                  <tr className='transaction-details-row'>
                    <td colSpan='6'>
                      <div className='transaction-details'>
                        <div className='detail-grid'>
                          <div className='detail-item'>
                            <strong>Order ID:</strong>
                            <span>{transaction.order_id}</span>
                          </div>
                          <div className='detail-item'>
                            <strong>Customer ID:</strong>
                            <span>{transaction.customer_id}</span>
                          </div>
                          <div className='detail-item'>
                            <strong>Gateway Transaction ID:</strong>
                            <span>
                              {transaction.gateway_transaction_id || "N/A"}
                            </span>
                          </div>
                          <div className='detail-item'>
                            <strong>Error Code:</strong>
                            <span>{transaction.error_code || "N/A"}</span>
                          </div>
                          <div className='detail-item'>
                            <strong>Error Message:</strong>
                            <span>{transaction.error_message || "N/A"}</span>
                          </div>
                          <div className='detail-item'>
                            <strong>Retry Count:</strong>
                            <span>{transaction.retry_count || 0}</span>
                          </div>
                        </div>
                      </div>
                    </td>
                  </tr>
                )}
              </React.Fragment>
            ))}
          </tbody>
        </table>
      </div>

      {transactions.length > 0 && (
        <div className='transaction-summary'>
          <p>
            Showing {transactions.length} {filterStatus || "transaction"}(s)
          </p>
        </div>
      )}
    </div>
  );
}

export default FailedTransactionsComponent;
