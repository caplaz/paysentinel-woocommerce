/**
 * Payment Monitor Dashboard - WordPress React Integration
 *
 * This file provides a WordPress-compatible React dashboard
 * that integrates with the WooCommerce Payment Monitor plugin
 */

(function (wp, React, ReactDOM) {
  "use strict";

  const { useState, useEffect, useCallback } = React;
  const { apiFetch } = wp;

  /**
   * Dashboard Component
   */
  function Dashboard() {
    const [healthData, setHealthData] = useState([]);
    const [transactions, setTransactions] = useState([]);
    const [alerts, setAlerts] = useState([]);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState(null);

    useEffect(() => {
      loadDashboardData();
    }, []);

    const loadDashboardData = async () => {
      try {
        setLoading(true);
        setError(null);

        // Get nonce from global object
        const nonce = window.wcPaymentMonitor?.nonce || "";

        const headers = {
          "Content-Type": "application/json",
        };

        if (nonce) {
          headers["X-WP-Nonce"] = nonce;
        }

        // Load gateway health data
        console.log(
          "Loading health data from:",
          "/wp-json/wc-payment-monitor/v1/health/gateways"
        );

        const healthResponse = await fetch(
          "/wp-json/wc-payment-monitor/v1/health/gateways",
          {
            method: "GET",
            headers,
            credentials: "same-origin",
          }
        );

        if (!healthResponse.ok) {
          console.error(
            "Health API error:",
            healthResponse.status,
            healthResponse.statusText
          );
        }

        const healthData = await healthResponse.json();
        console.log("Health response:", healthData);
        setHealthData(Array.isArray(healthData) ? healthData : []);

        // Load recent transactions
        console.log("Loading transactions...");

        const transactionsResponse = await fetch(
          "/wp-json/wc-payment-monitor/v1/transactions?per_page=10",
          {
            method: "GET",
            headers,
            credentials: "same-origin",
          }
        );

        if (!transactionsResponse.ok) {
          console.error(
            "Transactions API error:",
            transactionsResponse.status,
            transactionsResponse.statusText
          );
        }

        const transactionsData = await transactionsResponse.json();
        console.log("Transactions response:", transactionsData);
        setTransactions(
          Array.isArray(transactionsData?.items) ? transactionsData.items : []
        );

        // Load recent alerts
        console.log("Loading alerts...");

        const alertsResponse = await fetch(
          "/wp-json/wc-payment-monitor/v1/alerts?per_page=5",
          {
            method: "GET",
            headers,
            credentials: "same-origin",
          }
        );

        if (!alertsResponse.ok) {
          console.error(
            "Alerts API error:",
            alertsResponse.status,
            alertsResponse.statusText
          );
        }

        const alertsData = await alertsResponse.json();
        console.log("Alerts response:", alertsData);
        // Handle new direct response format (items in pagination) or legacy format (alerts)
        const alertsList = alertsData?.items || alertsData?.alerts || [];
        setAlerts(Array.isArray(alertsList) ? alertsList : []);

        setLoading(false);
      } catch (err) {
        console.error("Dashboard data loading error:", err);
        console.error("Error details:", {
          message: err.message,
          code: err.code,
          status: err.status,
          response: err.response,
        });
        setError(
          `Failed to load dashboard data. ${
            err.message || "Check browser console for details."
          }`
        );
        setLoading(false);
      }
    };

    if (loading) {
      return React.createElement(
        "div",
        { className: "wc-payment-monitor-loading" },
        React.createElement("p", null, "Loading dashboard data...")
      );
    }

    if (error) {
      return React.createElement(
        "div",
        { className: "wc-payment-monitor-error" },
        React.createElement("p", { style: { color: "red" } }, error)
      );
    }

    return React.createElement(
      "div",
      { className: "wc-payment-monitor-dashboard" },
      // Header
      React.createElement(
        "div",
        { className: "dashboard-header" },
        React.createElement("h2", null, "Payment Gateway Overview"),
        React.createElement(
          "button",
          {
            className: "button button-primary",
            onClick: loadDashboardData,
          },
          "Refresh Data"
        )
      ),

      // Gateway Health Cards
      React.createElement(
        "div",
        { className: "gateway-health-grid" },
        healthData.length > 0
          ? healthData.map((gateway) =>
              React.createElement(
                "div",
                {
                  key: gateway.gateway_id,
                  className:
                    "gateway-card " +
                    (gateway.success_rate >= 95
                      ? "healthy"
                      : gateway.success_rate >= 85
                      ? "warning"
                      : "critical"),
                },
                React.createElement("h3", null, gateway.gateway_id),
                React.createElement(
                  "div",
                  { className: "metric" },
                  React.createElement(
                    "span",
                    { className: "label" },
                    "Success Rate:"
                  ),
                  React.createElement(
                    "span",
                    { className: "value" },
                    gateway.success_rate + "%"
                  )
                ),
                React.createElement(
                  "div",
                  { className: "metric" },
                  React.createElement(
                    "span",
                    { className: "label" },
                    "Total Transactions:"
                  ),
                  React.createElement(
                    "span",
                    { className: "value" },
                    gateway.total_transactions
                  )
                ),
                React.createElement(
                  "div",
                  { className: "metric" },
                  React.createElement(
                    "span",
                    { className: "label" },
                    "Avg Response Time:"
                  ),
                  React.createElement(
                    "span",
                    { className: "value" },
                    gateway.avg_response_time + "ms"
                  )
                )
              )
            )
          : React.createElement("p", null, "No gateway health data available.")
      ),

      // Recent Transactions
      React.createElement(
        "div",
        { className: "recent-transactions" },
        React.createElement("h3", null, "Recent Transactions"),
        transactions.length > 0
          ? React.createElement(
              "table",
              { className: "wp-list-table widefat fixed striped" },
              React.createElement(
                "thead",
                null,
                React.createElement(
                  "tr",
                  null,
                  React.createElement("th", null, "ID"),
                  React.createElement("th", null, "Gateway"),
                  React.createElement("th", null, "Status"),
                  React.createElement("th", null, "Amount"),
                  React.createElement("th", null, "Time")
                )
              ),
              React.createElement(
                "tbody",
                null,
                transactions.map((tx) =>
                  React.createElement(
                    "tr",
                    { key: tx.id },
                    React.createElement("td", null, tx.id),
                    React.createElement("td", null, tx.gateway_id),
                    React.createElement(
                      "td",
                      null,
                      React.createElement(
                        "span",
                        {
                          className: "status-" + tx.status,
                        },
                        tx.status
                      )
                    ),
                    React.createElement(
                      "td",
                      null,
                      tx.amount ? "$" + tx.amount : "N/A"
                    ),
                    React.createElement(
                      "td",
                      null,
                      tx.created_at
                        ? new Date(tx.created_at).toLocaleString()
                        : "N/A"
                    )
                  )
                )
              )
            )
          : React.createElement("p", null, "No recent transactions.")
      ),

      // Recent Alerts
      React.createElement(
        "div",
        { className: "recent-alerts" },
        React.createElement("h3", null, "Recent Alerts"),
        alerts.length > 0
          ? React.createElement(
              "ul",
              { className: "alerts-list" },
              alerts.map((alert) =>
                React.createElement(
                  "li",
                  {
                    key: alert.id,
                    className: "alert-item alert-" + alert.severity,
                  },
                  React.createElement("strong", null, alert.title + ": "),
                  alert.message,
                  React.createElement(
                    "small",
                    null,
                    " (" + new Date(alert.created_at).toLocaleString() + ")"
                  )
                )
              )
            )
          : React.createElement("p", null, "No recent alerts.")
      )
    );
  }

  // Mount the dashboard when DOM is ready
  document.addEventListener("DOMContentLoaded", function () {
    const container = document.getElementById("wc-payment-monitor-root");
    if (container && React && ReactDOM) {
      const root = ReactDOM.createRoot(container);
      root.render(React.createElement(Dashboard));
    }

    // Mount GatewayHealth component if on health page
    const healthContainer = document.getElementById(
      "wc-payment-monitor-health-container"
    );
    if (healthContainer && React && ReactDOM) {
      const root = ReactDOM.createRoot(healthContainer);
      root.render(React.createElement(GatewayHealth));
    }

    // Mount Transactions component if on transactions page
    const transactionsContainer = document.getElementById(
      "wc-payment-monitor-transactions-container"
    );
    if (transactionsContainer && React && ReactDOM) {
      const root = ReactDOM.createRoot(transactionsContainer);
      root.render(React.createElement(Transactions));
    }

    // Mount Alerts component if on alerts page
    const alertsContainer = document.getElementById(
      "wc-payment-monitor-alerts-container"
    );
    if (alertsContainer && React && ReactDOM) {
      const root = ReactDOM.createRoot(alertsContainer);
      root.render(React.createElement(Alerts));
    }
  });

  /**
   * Gateway Health Component
   * Displays real-time health metrics for all payment gateways with historical trends
   */
  function GatewayHealth() {
    const [gateways, setGateways] = useState([]);
    const [isLoading, setIsLoading] = useState(false);
    const [error, setError] = useState(null);
    const [timePeriod, setTimePeriod] = useState("24h");
    const [expandedGateway, setExpandedGateway] = useState(null);

    // Fetch gateway health data
    const fetchGatewayHealth = useCallback(async () => {
      setIsLoading(true);
      setError(null);

      try {
        const nonce = window.wcPaymentMonitor?.nonce || "";
        const headers = {
          "Content-Type": "application/json",
        };

        if (nonce) {
          headers["X-WP-Nonce"] = nonce;
        }

        console.log("Fetching gateway health with period:", timePeriod);

        const response = await fetch(
          `/wp-json/wc-payment-monitor/v1/health/gateways?period=${timePeriod}`,
          {
            method: "GET",
            headers,
            credentials: "same-origin",
          }
        );

        console.log("Response status:", response.status, response.statusText);

        if (!response.ok) {
          const errorText = await response.text();
          console.error("Response error body:", errorText);
          throw new Error(
            `HTTP error! status: ${response.status} - ${errorText}`
          );
        }

        const data = await response.json();
        console.log("Gateway health data:", data);

        // Handle new direct response format (array) or legacy wrapped format
        if (Array.isArray(data)) {
          setGateways(data);
        } else if (data.success && data.data) {
          setGateways(data.data);
        } else {
          setError(data.message || "Failed to fetch gateway health");
        }
      } catch (err) {
        setError(err.message || "Failed to fetch gateway health");
        console.error("Gateway health fetch error:", err);
      } finally {
        setIsLoading(false);
      }
    }, [timePeriod]);

    // Auto-refresh effect
    useEffect(() => {
      fetchGatewayHealth();

      const intervalId = setInterval(() => {
        fetchGatewayHealth();
      }, 30000); // Refresh every 30 seconds

      return () => clearInterval(intervalId);
    }, [fetchGatewayHealth]);

    // Get status class based on health percentage
    const getStatusClass = (health) => {
      if (health >= 95) return "status-excellent";
      if (health >= 90) return "status-good";
      if (health >= 75) return "status-warning";
      return "status-critical";
    };

    // Get status text
    const getStatusText = (health) => {
      if (health >= 95) return "Excellent";
      if (health >= 90) return "Good";
      if (health >= 75) return "Warning";
      return "Critical";
    };

    // Get time period label
    const getTimePeriodLabel = () => {
      const periods = {
        "24h": "Last 24 Hours",
        "7d": "Last 7 Days",
        "30d": "Last 30 Days",
      };
      return periods[timePeriod] || "Last 24 Hours";
    };

    return React.createElement(
      "div",
      { className: "gateway-health-component" },

      // Header with time period selector
      React.createElement(
        "div",
        { className: "section-header" },
        React.createElement(
          "div",
          null,
          React.createElement("h2", null, "Gateway Health Status"),
          React.createElement(
            "p",
            { className: "section-subtitle" },
            "Real-time health metrics for all payment gateways"
          )
        ),
        React.createElement(
          "div",
          { className: "time-period-selector" },
          React.createElement("label", { htmlFor: "time-period" }, "View:"),
          React.createElement(
            "select",
            {
              id: "time-period",
              value: timePeriod,
              onChange: (e) => setTimePeriod(e.target.value),
            },
            React.createElement("option", { value: "24h" }, "Last 24 Hours"),
            React.createElement("option", { value: "7d" }, "Last 7 Days"),
            React.createElement("option", { value: "30d" }, "Last 30 Days")
          )
        )
      ),

      // Trend info
      React.createElement(
        "div",
        { className: "trend-info" },
        React.createElement("small", null, "Showing " + getTimePeriodLabel())
      ),

      // Error message
      error
        ? React.createElement("div", { className: "error-message" }, error)
        : null,

      // Loading state
      isLoading && gateways.length === 0
        ? React.createElement(
            "div",
            { className: "loading-state" },
            "Loading gateway health data..."
          )
        : null,

      // Empty state
      gateways.length === 0 && !isLoading && !error
        ? React.createElement(
            "div",
            { className: "empty-state" },
            "No gateway data available yet. Please check back soon."
          )
        : null,

      // Gateways grid
      React.createElement(
        "div",
        { className: "gateways-grid" },
        gateways.map((gateway) => {
          const isExpanded = expandedGateway === gateway.gateway_id;
          const trendData = gateway.trend_data || [];

          return React.createElement(
            "div",
            {
              key: gateway.gateway_id,
              className: "gateway-card " + (isExpanded ? "expanded" : ""),
            },

            // Gateway header with status
            React.createElement(
              "div",
              { className: "gateway-header" },
              React.createElement(
                "h3",
                null,
                gateway.gateway_name || gateway.gateway_id
              ),
              React.createElement(
                "span",
                {
                  className:
                    "status-badge " + getStatusClass(gateway.health_percentage),
                },
                getStatusText(gateway.health_percentage)
              )
            ),

            // Gateway stats
            React.createElement(
              "div",
              { className: "gateway-stats" },
              React.createElement(
                "div",
                { className: "stat" },
                React.createElement(
                  "span",
                  { className: "stat-label" },
                  "Health Score"
                ),
                React.createElement(
                  "span",
                  { className: "stat-value" },
                  gateway.health_percentage.toFixed(1) + "%"
                )
              ),
              React.createElement(
                "div",
                { className: "stat" },
                React.createElement(
                  "span",
                  { className: "stat-label" },
                  "Success Rate (24h)"
                ),
                React.createElement(
                  "span",
                  { className: "stat-value" },
                  gateway.success_rate_24h.toFixed(1) + "%"
                )
              ),
              React.createElement(
                "div",
                { className: "stat" },
                React.createElement(
                  "span",
                  { className: "stat-label" },
                  "Total Transactions"
                ),
                React.createElement(
                  "span",
                  { className: "stat-value" },
                  gateway.transaction_count
                )
              ),
              React.createElement(
                "div",
                { className: "stat" },
                React.createElement(
                  "span",
                  { className: "stat-label" },
                  "Failed (24h)"
                ),
                React.createElement(
                  "span",
                  { className: "stat-value error" },
                  gateway.failed_count_24h
                )
              )
            ),

            // Progress bar
            React.createElement(
              "div",
              { className: "gateway-chart" },
              React.createElement(
                "div",
                { className: "progress-bar" },
                React.createElement("div", {
                  className:
                    "progress-fill " +
                    getStatusClass(gateway.health_percentage),
                  style: {
                    width: gateway.health_percentage + "%",
                  },
                })
              )
            ),

            // Trend section (if data available)
            trendData.length > 0
              ? React.createElement(
                  "div",
                  { className: "trend-section" },
                  React.createElement(
                    "button",
                    {
                      className: "expand-trend-btn",
                      onClick: () =>
                        setExpandedGateway(
                          isExpanded ? null : gateway.gateway_id
                        ),
                    },
                    (isExpanded ? "Hide" : "Show") + " Historical Trend"
                  ),

                  // Expanded trend chart
                  isExpanded
                    ? React.createElement(
                        "div",
                        { className: "trend-chart" },
                        React.createElement(
                          "div",
                          { className: "trend-header" },
                          "Historical Trend - " + getTimePeriodLabel()
                        ),
                        React.createElement(
                          "div",
                          { className: "sparkline-container" },
                          React.createElement(
                            "div",
                            { className: "sparkline" },
                            trendData.map((point, idx) =>
                              React.createElement("div", {
                                key: idx,
                                className: "sparkline-bar",
                                style: {
                                  height:
                                    Math.max(20, point.health_score) + "%",
                                  opacity: 0.6 + (idx / trendData.length) * 0.4,
                                },
                                title:
                                  point.health_score.toFixed(1) +
                                  "% - " +
                                  new Date(point.timestamp).toLocaleString(),
                              })
                            )
                          )
                        ),
                        React.createElement(
                          "div",
                          { className: "trend-stats" },
                          React.createElement(
                            "div",
                            { className: "trend-stat" },
                            React.createElement(
                              "span",
                              null,
                              "Highest: " +
                                Math.max(
                                  ...trendData.map((p) => p.health_score)
                                ).toFixed(1) +
                                "%"
                            )
                          ),
                          React.createElement(
                            "div",
                            { className: "trend-stat" },
                            React.createElement(
                              "span",
                              null,
                              "Average: " +
                                (
                                  trendData.reduce(
                                    (a, p) => a + p.health_score,
                                    0
                                  ) / trendData.length
                                ).toFixed(1) +
                                "%"
                            )
                          ),
                          React.createElement(
                            "div",
                            { className: "trend-stat" },
                            React.createElement(
                              "span",
                              null,
                              "Lowest: " +
                                Math.min(
                                  ...trendData.map((p) => p.health_score)
                                ).toFixed(1) +
                                "%"
                            )
                          )
                        )
                      )
                    : null
                )
              : null
          );
        })
      )
    );
  }

  /**
   * Transactions Component
   * Displays transaction log with filtering and pagination
   */
  function Transactions() {
    const [transactions, setTransactions] = useState([]);
    const [isLoading, setIsLoading] = useState(false);
    const [error, setError] = useState(null);
    const [page, setPage] = useState(1);
    const [perPage, setPerPage] = useState(20);
    const [totalCount, setTotalCount] = useState(0);
    const [statusFilter, setStatusFilter] = useState("");

    // Fetch transactions
    const fetchTransactions = useCallback(async () => {
      setIsLoading(true);
      setError(null);

      try {
        const nonce = window.wcPaymentMonitor?.nonce || "";
        const headers = {
          "Content-Type": "application/json",
        };

        if (nonce) {
          headers["X-WP-Nonce"] = nonce;
        }

        let url = `/wp-json/wc-payment-monitor/v1/transactions?page=${page}&per_page=${perPage}`;

        if (statusFilter) {
          url += `&status=${statusFilter}`;
        }

        console.log("Fetching transactions from:", url);

        const response = await fetch(url, {
          method: "GET",
          headers,
          credentials: "same-origin",
        });

        console.log("Transactions response status:", response.status);

        if (!response.ok) {
          const errorText = await response.text();
          console.error("Response error body:", errorText);
          throw new Error(
            `HTTP error! status: ${response.status} - ${errorText}`
          );
        }

        const data = await response.json();
        console.log("Transactions data:", data);

        if (data.items && Array.isArray(data.items)) {
          setTransactions(data.items);
          setTotalCount(data.pagination?.total || 0);
        } else if (data.success && data.data) {
          setTransactions(data.data);
          setTotalCount(data.total || 0);
        } else {
          setError(data.message || "Failed to fetch transactions");
        }
      } catch (err) {
        setError(err.message || "Failed to fetch transactions");
        console.error("Transaction fetch error:", err);
      } finally {
        setIsLoading(false);
      }
    }, [page, perPage, statusFilter]);

    // Auto-refresh effect
    useEffect(() => {
      fetchTransactions();

      const intervalId = setInterval(() => {
        fetchTransactions();
      }, 30000); // Refresh every 30 seconds

      return () => clearInterval(intervalId);
    }, [fetchTransactions]);

    const totalPages = Math.ceil(totalCount / perPage);

    return React.createElement(
      "div",
      { className: "transactions-component" },

      // Header and filters
      React.createElement(
        "div",
        { className: "section-header" },
        React.createElement(
          "div",
          null,
          React.createElement("h2", null, "Transaction Log"),
          React.createElement(
            "p",
            { className: "section-subtitle" },
            "View all monitored payment transactions"
          )
        ),
        React.createElement(
          "div",
          { className: "filters" },
          React.createElement(
            "select",
            {
              value: statusFilter,
              onChange: (e) => {
                setStatusFilter(e.target.value);
                setPage(1);
              },
            },
            React.createElement("option", { value: "" }, "All Statuses"),
            React.createElement("option", { value: "success" }, "Success"),
            React.createElement("option", { value: "failed" }, "Failed"),
            React.createElement("option", { value: "pending" }, "Pending"),
            React.createElement("option", { value: "retry" }, "Retry")
          )
        )
      ),

      // Error message
      error
        ? React.createElement("div", { className: "error-message" }, error)
        : null,

      // Loading state
      isLoading && transactions.length === 0
        ? React.createElement(
            "div",
            { className: "loading-state" },
            "Loading transactions..."
          )
        : null,

      // Empty state
      transactions.length === 0 && !isLoading && !error
        ? React.createElement(
            "div",
            { className: "empty-state" },
            "No transactions found."
          )
        : null,

      // Transactions table
      transactions.length > 0
        ? React.createElement(
            "div",
            { className: "transactions-table-container" },
            React.createElement(
              "table",
              { className: "wp-list-table widefat fixed striped" },
              React.createElement(
                "thead",
                null,
                React.createElement(
                  "tr",
                  null,
                  React.createElement("th", null, "ID"),
                  React.createElement("th", null, "Order"),
                  React.createElement("th", null, "Gateway"),
                  React.createElement("th", null, "Amount"),
                  React.createElement("th", null, "Status"),
                  React.createElement("th", null, "Date")
                )
              ),
              React.createElement(
                "tbody",
                null,
                transactions.map((tx) =>
                  React.createElement(
                    "tr",
                    { key: tx.id },
                    React.createElement("td", null, tx.id),
                    React.createElement("td", null, tx.order_id || "N/A"),
                    React.createElement("td", null, tx.gateway_id),
                    React.createElement(
                      "td",
                      null,
                      tx.currency
                        ? tx.currency + " " + parseFloat(tx.amount).toFixed(2)
                        : "$" + parseFloat(tx.amount || 0).toFixed(2)
                    ),
                    React.createElement(
                      "td",
                      null,
                      React.createElement(
                        "span",
                        {
                          className: "status-badge status-" + (tx.status || ""),
                        },
                        tx.status || "unknown"
                      )
                    ),
                    React.createElement(
                      "td",
                      null,
                      tx.created_at
                        ? new Date(tx.created_at).toLocaleString()
                        : "N/A"
                    )
                  )
                )
              )
            )
          )
        : null,

      // Pagination
      transactions.length > 0 && totalPages > 1
        ? React.createElement(
            "div",
            { className: "pagination" },
            React.createElement(
              "button",
              {
                disabled: page <= 1,
                onClick: () => setPage(page - 1),
              },
              "Previous"
            ),
            React.createElement(
              "span",
              { className: "page-info" },
              "Page " + page + " of " + totalPages
            ),
            React.createElement(
              "button",
              {
                disabled: page >= totalPages,
                onClick: () => setPage(page + 1),
              },
              "Next"
            )
          )
        : null
    );
  }

  /**
   * Alerts Component
   * Displays payment monitoring alerts with filtering and actions
   */
  function Alerts() {
    const [alerts, setAlerts] = useState([]);
    const [isLoading, setIsLoading] = useState(true);
    const [error, setError] = useState(null);
    const [page, setPage] = useState(1);
    const [perPage, setPerPage] = useState(20);
    const [severityFilter, setSeverityFilter] = useState("all");
    const [statusFilter, setStatusFilter] = useState("all");
    const [totalCount, setTotalCount] = useState(0);

    const totalPages = Math.ceil(totalCount / perPage);

    const fetchAlerts = useCallback(() => {
      const nonce = window.wcPaymentMonitor?.nonce || "";

      const headers = {
        "Content-Type": "application/json",
      };

      if (nonce) {
        headers["X-WP-Nonce"] = nonce;
      }

      const params = new URLSearchParams();
      params.append("page", page);
      params.append("per_page", perPage);

      if (severityFilter !== "all") {
        params.append("severity", severityFilter);
      }

      if (statusFilter !== "all") {
        params.append("status", statusFilter);
      }

      const url = "/wp-json/wc-payment-monitor/v1/alerts?" + params.toString();

      try {
        setIsLoading(true);
        setError(null);

        console.log("Fetching alerts from:", url);

        fetch(url, {
          method: "GET",
          headers,
          credentials: "same-origin",
        })
          .then((response) => {
            console.log("Alerts response status:", response.status);

            if (!response.ok) {
              const errorText = response.text();
              console.error("Response error body:", errorText);
              throw new Error(
                `HTTP error! status: ${response.status} - ${errorText}`
              );
            }

            return response.json();
          })
          .then((data) => {
            console.log("Alerts data:", data);

            if (data.items && Array.isArray(data.items)) {
              setAlerts(data.items);
              setTotalCount(data.pagination?.total || 0);
            } else if (data.success && data.data) {
              setAlerts(data.data);
              setTotalCount(data.total || 0);
            } else {
              setError(data.message || "Failed to fetch alerts");
            }
          })
          .catch((err) => {
            setError(err.message || "Failed to fetch alerts");
            console.error("Alerts fetch error:", err);
          })
          .finally(() => {
            setIsLoading(false);
          });
      } catch (err) {
        setError(err.message || "Failed to fetch alerts");
        console.error("Alerts fetch error:", err);
        setIsLoading(false);
      }
    }, [page, perPage, severityFilter, statusFilter]);

    // Auto-refresh effect
    useEffect(() => {
      fetchAlerts();

      const intervalId = setInterval(() => {
        fetchAlerts();
      }, 30000); // Refresh every 30 seconds

      return () => clearInterval(intervalId);
    }, [page, perPage, severityFilter, statusFilter, fetchAlerts]);

    const handleResolveAlert = (alertId) => {
      const nonce = window.wcPaymentMonitor?.nonce || "";

      const headers = {
        "Content-Type": "application/json",
      };

      if (nonce) {
        headers["X-WP-Nonce"] = nonce;
      }

      fetch(`/wp-json/wc-payment-monitor/v1/alerts/${alertId}/resolve`, {
        method: "POST",
        headers,
        credentials: "same-origin",
      })
        .then((response) => {
          if (!response.ok) {
            throw new Error("Failed to resolve alert");
          }
          return response.json();
        })
        .then((data) => {
          console.log("Alert resolved:", data);
          // Refresh alerts after resolving
          fetchAlerts();
        })
        .catch((err) => {
          console.error("Error resolving alert:", err);
          setError("Failed to resolve alert");
        });
    };

    if (isLoading) {
      return React.createElement("p", null, "Loading alerts...");
    }

    if (error) {
      return React.createElement(
        "div",
        { className: "alert alert-error" },
        "Error: " + error
      );
    }

    return React.createElement(
      "div",
      { className: "alerts-component" },

      // Section Header with filters
      React.createElement(
        "div",
        { className: "section-header" },

        React.createElement(
          "div",
          null,
          React.createElement("h2", null, "Alerts"),
          React.createElement(
            "p",
            { className: "section-subtitle" },
            "View all payment monitoring alerts"
          )
        ),

        React.createElement(
          "div",
          { className: "filters" },

          React.createElement(
            "select",
            {
              value: severityFilter,
              onChange: (e) => {
                setSeverityFilter(e.target.value);
                setPage(1);
              },
            },
            React.createElement("option", { value: "all" }, "All Severity"),
            React.createElement("option", { value: "info" }, "Info"),
            React.createElement("option", { value: "warning" }, "Warning"),
            React.createElement("option", { value: "critical" }, "Critical")
          ),

          React.createElement(
            "select",
            {
              value: statusFilter,
              onChange: (e) => {
                setStatusFilter(e.target.value);
                setPage(1);
              },
            },
            React.createElement("option", { value: "all" }, "All Status"),
            React.createElement("option", { value: "active" }, "Active"),
            React.createElement("option", { value: "resolved" }, "Resolved")
          )
        )
      ),

      // Error message
      error
        ? React.createElement("div", { className: "error-message" }, error)
        : null,

      // Loading state
      isLoading && alerts.length === 0
        ? React.createElement(
            "div",
            { className: "loading-state" },
            "Loading alerts..."
          )
        : null,

      // Empty state
      alerts.length === 0 && !isLoading && !error
        ? React.createElement(
            "div",
            { className: "empty-state" },
            "No alerts found."
          )
        : null,

      // Alerts list
      alerts.length > 0
        ? React.createElement(
            "div",
            { className: "alerts-list-container" },
            alerts.map((alert) =>
              React.createElement(
                "div",
                {
                  key: alert.id,
                  className: "alert-row status-" + (alert.severity || "info"),
                },

                React.createElement(
                  "div",
                  { className: "alert-cell alert-title-cell" },
                  React.createElement("strong", null, alert.title),
                  React.createElement(
                    "div",
                    { className: "alert-message-text" },
                    alert.message
                  )
                ),

                React.createElement(
                  "div",
                  { className: "alert-cell alert-gateway-cell" },
                  alert.gateway_id || "—"
                ),

                React.createElement(
                  "div",
                  { className: "alert-cell alert-severity-cell" },
                  React.createElement(
                    "span",
                    {
                      className: "severity-badge " + (alert.severity || "info"),
                    },
                    alert.severity?.toUpperCase() || "INFO"
                  )
                ),

                React.createElement(
                  "div",
                  { className: "alert-cell alert-status-cell" },
                  React.createElement(
                    "span",
                    {
                      className: "status-badge " + (alert.status || "active"),
                    },
                    alert.status?.toUpperCase() || "ACTIVE"
                  )
                ),

                React.createElement(
                  "div",
                  { className: "alert-cell alert-date-cell" },
                  alert.created_at
                    ? new Date(alert.created_at).toLocaleDateString() +
                        " " +
                        new Date(alert.created_at).toLocaleTimeString()
                    : "—"
                ),

                alert.status === "active"
                  ? React.createElement(
                      "div",
                      { className: "alert-cell alert-action-cell" },
                      React.createElement(
                        "button",
                        {
                          className: "button button-small",
                          onClick: () => handleResolveAlert(alert.id),
                        },
                        "Resolve"
                      )
                    )
                  : null
              )
            )
          )
        : null,

      // Pagination
      alerts.length > 0 && totalPages > 1
        ? React.createElement(
            "div",
            { className: "pagination" },
            React.createElement(
              "button",
              {
                className: "button",
                disabled: page <= 1,
                onClick: () => setPage(page - 1),
              },
              "← Previous"
            ),
            React.createElement(
              "span",
              { className: "page-info" },
              "Page " + page + " of " + totalPages
            ),
            React.createElement(
              "button",
              {
                className: "button",
                disabled: page >= totalPages,
                onClick: () => setPage(page + 1),
              },
              "Next →"
            )
          )
        : null
    );
  }
})(window.wp, window.React, window.ReactDOM);
