/**
 * Payment Monitor Dashboard - WordPress React Integration
 *
 * This file provides a WordPress-compatible React dashboard
 * that integrates with the WooCommerce Payment Monitor plugin
 */

(function (wp, React, ReactDOM) {
  "use strict";

  const { useState, useEffect } = React;
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
        setAlerts(Array.isArray(alertsData?.alerts) ? alertsData.alerts : []);

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
  });
})(window.wp, window.React, window.ReactDOM);
