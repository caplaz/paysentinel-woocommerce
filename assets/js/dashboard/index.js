/**
 * Payment Monitor Dashboard - WordPress React Integration
 *
 * This file provides a WordPress-compatible React dashboard
 * that integrates with the PaySentinel - Payment Monitor for WooCommerce plugin
 */

(function (wp, React, ReactDOM) {
  "use strict";

  const { useState, useEffect, useCallback } = React;
  const { apiFetch } = wp;

  /**
   * Normalize gateway health payloads into a consistent shape
   */
  function normalizeGateways(data) {
    if (!Array.isArray(data)) return [];

    return data.map((gateway) => {
      const successRate = parseFloat(
        gateway.success_rate ??
          gateway.success_rate_24h ??
          gateway.health_percentage ??
          0,
      );

      const failed24h =
        gateway.failed_transactions ??
        gateway.failed_count_24h ??
        gateway.failed ??
        gateway.failed_transactions_24h ??
        0;

      return {
        gateway_id:
          gateway.gateway_id || gateway.id || gateway.slug || "unknown",
        gateway_name:
          gateway.gateway_name ||
          gateway.name ||
          gateway.title ||
          gateway.gateway_id,
        success_rate: successRate,
        success_rate_24h: parseFloat(
          gateway.success_rate_24h ?? gateway.success_rate ?? successRate,
        ),
        health_percentage: parseFloat(
          gateway.health_percentage ?? gateway.success_rate ?? successRate,
        ),
        total_transactions:
          gateway.total_transactions ?? gateway.transaction_count ?? 0,
        transaction_count:
          gateway.transaction_count ?? gateway.total_transactions ?? 0,
        failed_transactions: failed24h,
        failed_count_24h: failed24h,
        avg_response_time:
          gateway.avg_response_time ??
          gateway.connectivity_response_time_ms ??
          0,
        connectivity_status: gateway.connectivity_status ?? null,
        connectivity_message: gateway.connectivity_message ?? "",
        connectivity_response_time_ms:
          gateway.connectivity_response_time_ms ??
          gateway.avg_response_time ??
          null,
        connectivity_checked_at: gateway.connectivity_checked_at ?? null,
        last_failure: gateway.last_failure || gateway.last_failure_at || null,
        last_checked: gateway.last_checked || gateway.calculated_at || null,
        trend_data: gateway.trend_data || gateway.history || [],
      };
    });
  }

  /**
   * HealthTrendChart Component
   * Renders a Chart.js line chart for gateway health trends
   */
  function HealthTrendChart({ data, period, gatewayId }) {
    const { useRef, useEffect: useEffectLocal } = React;
    const canvasRef = useRef(null);
    const chartRef = useRef(null);

    useEffectLocal(() => {
      if (!canvasRef.current || !data || data.length === 0) return;

      // Access Chart.js from global scope (loaded via CDN)
      const Chart = window.Chart;
      if (!Chart) {
        console.warn("Chart.js not loaded");
        return;
      }

      // Destroy existing chart if present
      if (chartRef.current) {
        chartRef.current.destroy();
      }

      const ctx = canvasRef.current.getContext("2d");

      // Prepare labels and data
      const labels = data.map((point) => {
        const date = new Date(point.timestamp);
        if (period === "24h") {
          return date.toLocaleTimeString([], {
            hour: "2-digit",
            minute: "2-digit",
          });
        }
        return date.toLocaleDateString([], { month: "short", day: "numeric" });
      });

      const healthScores = data.map((point) => point.health_score);

      // Determine color based on average health
      const avgHealth =
        healthScores.reduce((a, b) => a + b, 0) / healthScores.length;
      let lineColor, bgColor;
      if (avgHealth >= 95) {
        lineColor = "#00a32a";
        bgColor = "rgba(0, 163, 42, 0.1)";
      } else if (avgHealth >= 90) {
        lineColor = "#72aee6";
        bgColor = "rgba(114, 174, 230, 0.1)";
      } else if (avgHealth >= 75) {
        lineColor = "#dba617";
        bgColor = "rgba(219, 166, 23, 0.1)";
      } else {
        lineColor = "#d63638";
        bgColor = "rgba(214, 54, 56, 0.1)";
      }

      chartRef.current = new Chart(ctx, {
        type: "line",
        data: {
          labels,
          datasets: [
            {
              label: "Health Score",
              data: healthScores,
              borderColor: lineColor,
              backgroundColor: bgColor,
              fill: true,
              tension: 0.4,
              pointRadius: period === "24h" ? 2 : 3,
              pointHoverRadius: 5,
              borderWidth: 2,
            },
          ],
        },
        options: {
          responsive: true,
          maintainAspectRatio: false,
          animation: { duration: 500, easing: "easeInOutQuart" },
          interaction: { intersect: false, mode: "index" },
          plugins: {
            legend: { display: false },
            tooltip: {
              backgroundColor: "rgba(0, 0, 0, 0.8)",
              titleFont: { size: 12, weight: "bold" },
              bodyFont: { size: 11 },
              padding: 10,
              displayColors: false,
              callbacks: {
                title: (context) => {
                  const point = data[context[0].dataIndex];
                  return new Date(point.timestamp).toLocaleString();
                },
                label: (context) =>
                  "Health: " + context.parsed.y.toFixed(1) + "%",
              },
            },
          },
          scales: {
            x: {
              grid: { display: false },
              ticks: {
                maxRotation: 0,
                autoSkip: true,
                maxTicksLimit: period === "24h" ? 6 : 7,
                font: { size: 10 },
                color: "#646970",
              },
            },
            y: {
              min: Math.max(0, Math.min(...healthScores) - 10),
              max: 100,
              grid: { color: "rgba(0, 0, 0, 0.05)" },
              ticks: {
                callback: (value) => value + "%",
                font: { size: 10 },
                color: "#646970",
              },
            },
          },
        },
      });

      return () => {
        if (chartRef.current) {
          chartRef.current.destroy();
        }
      };
    }, [data, period, gatewayId]);

    if (!data || data.length === 0) {
      return React.createElement(
        "div",
        { className: "chart-empty-state" },
        "No trend data available",
      );
    }

    return React.createElement(
      "div",
      { className: "health-trend-chart", style: { height: "180px" } },
      React.createElement("canvas", { ref: canvasRef }),
    );
  }

  /**
   * Dashboard Component
   */
  function Dashboard() {
    const [healthData, setHealthData] = useState([]);
    const [transactions, setTransactions] = useState([]);
    const [alerts, setAlerts] = useState([]);
    const [revenueSummary, setRevenueSummary] = useState(null);
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

        // Load metrics summary (includes potential revenue stats in PRO)
        const summaryResponse = await fetch(
          `${window.wcPaymentMonitor.apiUrl}/analytics/metrics-summary`,
          {
            method: "GET",
            headers,
            credentials: "same-origin",
          },
        );

        if (summaryResponse.ok) {
          const summaryData = await summaryResponse.json();
          if (
            summaryData.success !== false &&
            summaryData.data?.revenue_summary
          ) {
            setRevenueSummary(summaryData.data.revenue_summary);
          }
        }

        // Load gateway health data
        console.log(
          "Loading health data from:",
          `${window.wcPaymentMonitor.apiUrl}/health/gateways`,
        );

        const healthResponse = await fetch(
          `${window.wcPaymentMonitor.apiUrl}/health/gateways`,
          {
            method: "GET",
            headers,
            credentials: "same-origin",
          },
        );

        if (!healthResponse.ok) {
          console.error(
            "Health API error:",
            healthResponse.status,
            healthResponse.statusText,
          );
          throw new Error(`Health API error: ${healthResponse.status}`);
        }

        const healthData = await healthResponse.json();
        console.log("Health response:", healthData);

        // Check if the response indicates success
        if (healthData.success === false) {
          console.error(
            "Health API error:",
            healthData.message || healthData.code,
          );
          setHealthData([]);
          return;
        }

        const normalizedHealth = normalizeGateways(
          healthData.data?.items || [],
        );
        setHealthData(normalizedHealth);

        // Load recent transactions
        console.log("Loading transactions...");

        const transactionsResponse = await fetch(
          `${window.wcPaymentMonitor.apiUrl}/transactions?per_page=10`,
          {
            method: "GET",
            headers,
            credentials: "same-origin",
          },
        );

        if (!transactionsResponse.ok) {
          console.error(
            "Transactions API error:",
            transactionsResponse.status,
            transactionsResponse.statusText,
          );
          throw new Error(
            `Transactions API error: ${transactionsResponse.status}`,
          );
        }

        const transactionsData = await transactionsResponse.json();
        console.log("Transactions response:", transactionsData);

        if (transactionsData.success === false) {
          console.error(
            "Transactions API error:",
            transactionsData.message || transactionsData.code,
          );
          setTransactions([]);
          return;
        }

        setTransactions(transactionsData.data?.items || []);

        // Load recent alerts
        console.log("Loading alerts...");

        const alertsResponse = await fetch(
          `${window.wcPaymentMonitor.apiUrl}/alerts?per_page=5`,
          {
            method: "GET",
            headers,
            credentials: "same-origin",
          },
        );

        if (!alertsResponse.ok) {
          console.error(
            "Alerts API error:",
            alertsResponse.status,
            alertsResponse.statusText,
          );
          throw new Error(`Alerts API error: ${alertsResponse.status}`);
        }

        const alertsData = await alertsResponse.json();
        console.log("Alerts response:", alertsData);

        if (alertsData.success === false) {
          console.error(
            "Alerts API error:",
            alertsData.message || alertsData.code,
          );
          setAlerts([]);
          return;
        }

        setAlerts(alertsData.data?.items || []);

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
          }`,
        );
        setLoading(false);
      }
    };

    if (loading) {
      return React.createElement(
        "div",
        { className: "paysentinel-loading" },
        React.createElement("p", null, "Loading dashboard data..."),
      );
    }

    if (error) {
      return React.createElement(
        "div",
        { className: "paysentinel-error" },
        React.createElement("p", { style: { color: "red" } }, error),
      );
    }

    return React.createElement(
      "div",
      { className: "paysentinel-dashboard" },
      // Header
      React.createElement(
        "div",
        { className: "dashboard-header" },
        React.createElement("h2", null, "Payment Gateway Overview"),
        React.createElement(
          "div",
          { style: { display: "flex", gap: "15px", alignItems: "center" } },
          window.wcPaymentMonitor &&
            window.wcPaymentMonitor.license &&
            React.createElement(
              "div",
              {
                style: {
                  background: window.wcPaymentMonitor.license.color,
                  color: "white",
                  padding: "6px 12px",
                  borderRadius: "4px",
                  fontWeight: "bold",
                  fontSize: "13px",
                  display: "inline-flex",
                  alignItems: "center",
                },
              },
              React.createElement("span", {
                className: "dashicons dashicons-awards",
                style: {
                  fontSize: "16px",
                  width: "16px",
                  height: "16px",
                  marginRight: "5px",
                },
              }),
              window.wcPaymentMonitor.license.label + " Plan",
            ),
          window.wcPaymentMonitor.license.tier === "free" &&
            React.createElement(
              "a",
              {
                href: "admin.php?page=paysentinel-settings&tab=license",
                className: "button button-primary",
                style: { display: "inline-flex", alignItems: "center" },
              },
              React.createElement("span", {
                className: "dashicons dashicons-star-filled",
                style: {
                  fontSize: "16px",
                  width: "16px",
                  height: "16px",
                  marginRight: "5px",
                },
              }),
              "Upgrade to Pro",
            ),
          React.createElement(
            "button",
            {
              className: "button", // Changed to default button if there's a primary upgrade button next to it
              onClick: loadDashboardData,
            },
            "Refresh Data",
          ),
        ),
      ),

      // Revenue Recovery Stats (PRO ONLY)
      revenueSummary &&
        React.createElement(
          "div",
          {
            className: "revenue-stats-grid",
            style: {
              display: "grid",
              gridTemplateColumns: "repeat(auto-fit, minmax(200px, 1fr))",
              gap: "20px",
              marginBottom: "30px",
            },
          },
          React.createElement(
            "div",
            {
              className: "stat-card lost-revenue",
              style: {
                background: "#fff",
                padding: "20px",
                borderRadius: "8px",
                borderLeft: "4px solid #d63638",
                boxShadow: "0 1px 3px rgba(0,0,0,0.1)",
              },
            },
            React.createElement(
              "div",
              {
                style: {
                  fontSize: "12px",
                  textTransform: "uppercase",
                  color: "#646970",
                  marginBottom: "5px",
                },
              },
              "Potential Revenue Lost (30d)",
            ),
            React.createElement(
              "div",
              {
                style: {
                  fontSize: "24px",
                  fontWeight: "bold",
                  color: "#d63638",
                },
              },
              "$" + revenueSummary.total_lost.toLocaleString(),
            ),
          ),
          React.createElement(
            "div",
            {
              className: "stat-card recovered-revenue",
              style: {
                background: "#fff",
                padding: "20px",
                borderRadius: "8px",
                borderLeft: "4px solid #00a32a",
                boxShadow: "0 1px 3px rgba(0,0,0,0.1)",
              },
            },
            React.createElement(
              "div",
              {
                style: {
                  fontSize: "12px",
                  textTransform: "uppercase",
                  color: "#646970",
                  marginBottom: "5px",
                },
              },
              "Recovered Revenue (30d)",
            ),
            React.createElement(
              "div",
              {
                style: {
                  fontSize: "24px",
                  fontWeight: "bold",
                  color: "#00a32a",
                },
              },
              "$" + revenueSummary.total_recovered.toLocaleString(),
            ),
          ),
          React.createElement(
            "div",
            {
              className: "stat-card recovery-rate",
              style: {
                background: "#fff",
                padding: "20px",
                borderRadius: "8px",
                borderLeft: "4px solid #2271b1",
                boxShadow: "0 1px 3px rgba(0,0,0,0.1)",
              },
            },
            React.createElement(
              "div",
              {
                style: {
                  fontSize: "12px",
                  textTransform: "uppercase",
                  color: "#646970",
                  marginBottom: "5px",
                },
              },
              "Recovery Success Rate",
            ),
            React.createElement(
              "div",
              {
                style: {
                  fontSize: "24px",
                  fontWeight: "bold",
                  color: "#2271b1",
                },
              },
              revenueSummary.total_lost > 0
                ? Math.round(
                    (revenueSummary.total_recovered /
                      (revenueSummary.total_lost +
                        revenueSummary.total_recovered)) *
                      100,
                  ) + "%"
                : "100%",
            ),
          ),
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
                    (gateway.total_transactions === 0
                      ? "neutral"
                      : gateway.success_rate >= 95
                        ? "healthy"
                        : gateway.success_rate >= 85
                          ? "warning"
                          : "critical"),
                },
                React.createElement(
                  "h3",
                  null,
                  gateway.gateway_name || gateway.gateway_id,
                ),
                React.createElement(
                  "div",
                  { className: "metric" },
                  React.createElement(
                    "span",
                    { className: "label" },
                    "Success Rate:",
                  ),
                  React.createElement(
                    "span",
                    { className: "value" },
                    gateway.total_transactions === 0
                      ? "N/A"
                      : gateway.success_rate + "%",
                  ),
                ),
                React.createElement(
                  "div",
                  { className: "metric" },
                  React.createElement(
                    "span",
                    { className: "label" },
                    "Total Transactions:",
                  ),
                  React.createElement(
                    "span",
                    { className: "value" },
                    gateway.total_transactions,
                  ),
                ),
                React.createElement(
                  "div",
                  { className: "metric" },
                  React.createElement(
                    "span",
                    { className: "label" },
                    "Avg Response Time:",
                  ),
                  React.createElement(
                    "span",
                    { className: "value" },
                    gateway.total_transactions === 0
                      ? "N/A"
                      : gateway.avg_response_time + "ms",
                  ),
                ),
              ),
            )
          : React.createElement("p", null, "No gateway health data available."),
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
                "colgroup",
                null,
                React.createElement("col", { style: { width: "10%" } }),
                React.createElement("col", { style: { width: "15%" } }),
                React.createElement("col", { style: { width: "15%" } }),
                React.createElement("col", { style: { width: "30%" } }),
                React.createElement("col", { style: { width: "30%" } }),
              ),
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
                  React.createElement("th", null, "Time"),
                ),
              ),
              React.createElement(
                "tbody",
                null,
                transactions.map((tx) =>
                  React.createElement(
                    "tr",
                    { key: tx.id },
                    React.createElement("td", null, tx.id),
                    React.createElement(
                      "td",
                      null,
                      tx.gateway_name || tx.gateway_id,
                    ),
                    React.createElement(
                      "td",
                      null,
                      React.createElement(
                        "span",
                        {
                          className: "status-" + tx.status,
                        },
                        tx.status,
                      ),
                    ),
                    React.createElement(
                      "td",
                      null,
                      tx.amount ? "$" + tx.amount : "N/A",
                    ),
                    React.createElement(
                      "td",
                      null,
                      tx.created_at
                        ? new Date(tx.created_at).toLocaleString()
                        : "N/A",
                    ),
                  ),
                ),
              ),
            )
          : React.createElement(
              "p",
              { style: { marginLeft: "20px" } },
              "No recent transactions.",
            ),
      ),

      // Recent Alerts
      React.createElement(
        "div",
        { className: "recent-alerts" },
        React.createElement("h3", null, "Recent Alerts"),
        alerts.length > 0
          ? React.createElement(
              "table",
              { className: "wp-list-table widefat fixed striped" },
              React.createElement(
                "colgroup",
                null,
                React.createElement("col", { style: { width: "50%" } }),
                React.createElement("col", { style: { width: "15%" } }),
                React.createElement("col", { style: { width: "15%" } }),
                React.createElement("col", { style: { width: "20%" } }),
              ),
              React.createElement(
                "thead",
                null,
                React.createElement(
                  "tr",
                  null,
                  React.createElement("th", null, "Alert"),
                  React.createElement("th", null, "Gateway"),
                  React.createElement("th", null, "Severity"),
                  React.createElement("th", null, "Date"),
                ),
              ),
              React.createElement(
                "tbody",
                null,
                alerts.map((alert) =>
                  React.createElement(
                    "tr",
                    {
                      key: alert.id,
                      className: "alert-row alert-" + alert.severity,
                    },
                    React.createElement(
                      "td",
                      null,
                      React.createElement("strong", null, alert.title),
                      React.createElement("br"),
                      React.createElement("small", null, alert.message),
                    ),
                    React.createElement(
                      "td",
                      null,
                      alert.gateway_name || alert.gateway_id || "-",
                    ),
                    React.createElement(
                      "td",
                      null,
                      React.createElement(
                        "span",
                        {
                          className: "severity-badge " + alert.severity,
                        },
                        alert.severity.charAt(0).toUpperCase() +
                          alert.severity.slice(1),
                      ),
                    ),
                    React.createElement(
                      "td",
                      null,
                      new Date(alert.created_at).toLocaleString(),
                    ),
                  ),
                ),
              ),
            )
          : React.createElement(
              "p",
              { style: { marginLeft: "20px" } },
              "No recent alerts.",
            ),
      ),
    );
  }

  // Mount the dashboard when DOM is ready
  document.addEventListener("DOMContentLoaded", function () {
    const container = document.getElementById("paysentinel-root");
    if (container && React && ReactDOM) {
      const root = ReactDOM.createRoot(container);
      root.render(React.createElement(Dashboard));
    }

    // Mount GatewayHealth component if on health page
    const healthContainer = document.getElementById(
      "paysentinel-health-container",
    );
    if (healthContainer && React && ReactDOM) {
      const root = ReactDOM.createRoot(healthContainer);
      root.render(React.createElement(GatewayHealth));
    }

    // Mount Transactions component if on transactions page
    const transactionsContainer = document.getElementById(
      "paysentinel-transactions-container",
    );
    if (transactionsContainer && React && ReactDOM) {
      const root = ReactDOM.createRoot(transactionsContainer);
      root.render(React.createElement(Transactions));
    }

    // Mount Alerts component if on alerts page
    const alertsContainer = document.getElementById(
      "paysentinel-alerts-container",
    );
    if (alertsContainer && React && ReactDOM) {
      const root = ReactDOM.createRoot(alertsContainer);
      root.render(React.createElement(Alerts));
    }

    // Mount Analytics component if on analytics page
    const analyticsContainer = document.getElementById(
      "paysentinel-analytics-container",
    );
    if (analyticsContainer && React && ReactDOM) {
      const root = ReactDOM.createRoot(analyticsContainer);
      if (document.getElementById("paysentinel-root")) {
        // Fix: If both exist, the root component is the main dashboard,
        // we'll let it handle routing if needed, but for now we render separately
        root.render(React.createElement(Analytics));
      } else {
        root.render(React.createElement(Analytics));
      }
    }
  });

  /**
   * Trend Chart Component using Chart.js
   */
  function RecoveryTrendChart({ data, isDemo }) {
    const chartRef = React.useRef(null);
    const chartInstance = React.useRef(null);

    React.useEffect(() => {
      if (!chartRef.current || !window.Chart) return;

      if (chartInstance.current) {
        chartInstance.current.destroy();
      }

      const ctx = chartRef.current.getContext("2d");
      const labels = Object.keys(data).map((date) =>
        new Date(date).toLocaleDateString("en-US", {
          month: "short",
          day: "numeric",
        }),
      );
      const values = Object.values(data).map((d) => d.recovered_amount || 0);
      const counts = Object.values(data).map((d) => d.recovered_count || 0);

      chartInstance.current = new window.Chart(ctx, {
        type: "line",
        data: {
          labels: labels,
          datasets: [
            {
              label: "Amount Recovered ($)",
              data: values,
              borderColor: "#46b450",
              backgroundColor: "rgba(70, 180, 80, 0.1)",
              borderWidth: 3,
              fill: true,
              tension: 0.4,
              yAxisID: "y",
            },
            {
              label: "Orders Recovered",
              data: counts,
              borderColor: "#0073aa",
              backgroundColor: "transparent",
              borderWidth: 2,
              borderDash: [5, 5],
              fill: false,
              tension: 0.4,
              yAxisID: "y1",
            },
          ],
        },
        options: {
          responsive: true,
          maintainAspectRatio: false,
          interaction: {
            mode: "index",
            intersect: false,
          },
          plugins: {
            legend: {
              position: "top",
              labels: {
                usePointStyle: true,
                padding: 20,
              },
            },
            tooltip: {
              padding: 12,
              backgroundColor: "rgba(0,0,0,0.8)",
            },
          },
          scales: {
            y: {
              type: "linear",
              display: true,
              position: "left",
              beginAtZero: true,
              grid: {
                drawBorder: false,
                color: "rgba(0,0,0,0.05)",
              },
              title: {
                display: true,
                text: "Amount ($)",
              },
            },
            y1: {
              type: "linear",
              display: true,
              position: "right",
              beginAtZero: true,
              grid: {
                drawOnChartArea: false,
              },
              title: {
                display: true,
                text: "Order Count",
              },
            },
            x: {
              grid: {
                display: false,
              },
            },
          },
        },
      });

      return () => {
        if (chartInstance.current) {
          chartInstance.current.destroy();
        }
      };
    }, [data]);

    return React.createElement(
      "div",
      { className: "paysentinel-chart-container", style: { height: "300px" } },
      React.createElement("canvas", { ref: chartRef }),
    );
  }

  /**
   * Analytics Component
   * Displays advanced payment performance and recovery ROI metrics
   */
  function Analytics() {
    const [summary, setSummary] = useState(null);
    const [isLoading, setIsLoading] = useState(true);
    const [error, setError] = useState(null);
    const [isDemo, setIsDemo] = useState(false);

    useEffect(() => {
      async function fetchAnalytics() {
        try {
          const nonce = window.wcPaymentMonitor?.nonce || "";
          const headers = { "Content-Type": "application/json" };
          if (nonce) headers["X-WP-Nonce"] = nonce;

          const response = await fetch(
            `${window.wcPaymentMonitor.apiUrl}/analytics/metrics-summary`,
            { method: "GET", headers, credentials: "same-origin" },
          );

          if (response.ok) {
            const result = await response.json();
            if (result.success !== false) {
              setSummary(result.data);
            } else {
              setError(result.message || "Failed to load analytics");
            }
          } else if (response.status === 403 || response.status === 401) {
            // Demo mode for unlicensed users
            setIsDemo(true);
            const demoTrends = {};
            const now = new Date();
            for (let i = 14; i >= 0; i--) {
              const d = new Date(now);
              d.setDate(d.getDate() - i);
              const dateStr = d.toISOString().split("T")[0];
              demoTrends[dateStr] = {
                recovered_count: Math.floor(Math.random() * 10) + 2,
                recovered_amount: Math.floor(Math.random() * 500) + 100,
              };
            }

            setSummary({
              revenue_summary: { total_recovered: 12482, total_lost: 1420 },
              daily_trends: demoTrends,
              gateway_metrics: {
                stripe: { periods: { "24hour": { success_rate: 94.2 } } },
                paypal: { periods: { "24hour": { success_rate: 89.5 } } },
              },
            });
          } else {
            setError("Failed to fetch analytics data");
          }
        } catch (err) {
          setError(err.message);
        } finally {
          setIsLoading(false);
        }
      }
      fetchAnalytics();
    }, []);

    if (isLoading)
      return React.createElement(
        "div",
        { className: "paysentinel-loading" },
        "Loading analytics...",
      );
    if (error)
      return React.createElement(
        "div",
        { className: "notice notice-error" },
        React.createElement("p", null, error),
      );
    if (!summary) return null;

    const { revenue_summary, gateway_metrics, daily_trends } = summary;

    const handleExport = () => {
      if (isDemo) return;
      const csvRows = [
        [
          "Gateway",
          "Period",
          "Success Rate",
          "Transactions",
          "Successful",
          "Failed",
        ],
      ];

      Object.entries(gateway_metrics).forEach(([id, data]) => {
        Object.entries(data.periods || {}).forEach(([period, stats]) => {
          csvRows.push([
            id,
            period,
            `${stats.success_rate.toFixed(2)}%`,
            stats.total_transactions,
            stats.successful_transactions,
            stats.failed_transactions,
          ]);
        });
      });

      const csvContent =
        "data:text/csv;charset=utf-8," +
        csvRows.map((e) => e.join(",")).join("\n");
      const encodedUri = encodeURI(csvContent);
      const link = document.createElement("a");
      link.setAttribute("href", encodedUri);
      link.setAttribute(
        "download",
        `paysentinel-analytics-${new Date().toISOString().split("T")[0]}.csv`,
      );
      document.body.appendChild(link);
      link.click();
      document.body.removeChild(link);
    };

    return React.createElement(
      "div",
      {
        className: `paysentinel-analytics-dashboard ${isDemo ? "demo-mode" : ""}`,
      },
      isDemo &&
        React.createElement(
          "div",
          {
            className: "notice notice-info",
            style: {
              marginBottom: "20px",
              display: "flex",
              justifyContent: "space-between",
              alignItems: "center",
              padding: "10px 20px",
            },
          },
          React.createElement(
            "p",
            { style: { margin: 0 } },
            "Showing demo data. A PRO license is required to see your actual store analytics.",
          ),
          React.createElement(
            "a",
            {
              href: `${window.wcPaymentMonitor?.saasUrl}/upgrade`,
              target: "_blank",
              className: "button button-primary",
            },
            "Upgrade to PRO",
          ),
        ),
      // ROI Cards Row
      React.createElement(
        "div",
        {
          style: {
            display: "flex",
            gap: "20px",
            marginBottom: "30px",
            opacity: isDemo ? 0.8 : 1,
          },
        },
        React.createElement(AnalyticsCard, {
          title: "Recovery ROI",
          value: `$${(revenue_summary.total_recovered || 0).toLocaleString()}`,
          subtitle: "Revenue rescued via retries",
          color: "#46b450",
          onExport: isDemo ? null : handleExport,
        }),
        React.createElement(AnalyticsCard, {
          title: "Lost Revenue",
          value: `$${(revenue_summary.total_lost || 0).toLocaleString()}`,
          subtitle: "Failed transactions (last 30d)",
          color: "#dc3232",
        }),
      ),

      // Trend Chart
      React.createElement(
        "div",
        {
          className: "paysentinel-card",
          style: { marginBottom: "30px", opacity: isDemo ? 0.8 : 1 },
        },
        React.createElement("h2", null, "Recovery Performance Trends"),
        daily_trends &&
          React.createElement(RecoveryTrendChart, {
            data: daily_trends,
            isDemo: isDemo,
          }),
      ),

      // Recovery Intelligence Flow
      React.createElement(
        "div",
        {
          className: "paysentinel-card",
          style: {
            marginBottom: "30px",
            opacity: isDemo ? 0.8 : 1,
            padding: "20px",
          },
        },
        React.createElement(
          "h2",
          { style: { marginTop: 0, marginBottom: "25px" } },
          "Recovery Intelligence Flow",
        ),
        React.createElement(RecoveryFlow, { summary }),
      ),

      // Gateway Comparison Table
      React.createElement(
        "div",
        { className: "paysentinel-card", style: { opacity: isDemo ? 0.8 : 1 } },
        React.createElement("h2", null, "Gateway Performance Comparison"),
        React.createElement(
          "table",
          { className: "wp-list-table widefat fixed striped" },
          React.createElement(
            "thead",
            null,
            React.createElement(
              "tr",
              null,
              React.createElement("th", null, "Gateway"),
              React.createElement("th", null, "Success Rate (24h)"),
              React.createElement("th", null, "Recovery ROI"),
              React.createElement("th", null, "Recovered Orders"),
            ),
          ),
          React.createElement(
            "tbody",
            null,
            Object.entries(gateway_metrics || {}).map(([id, data]) => {
              // Calculate recovery ROI from trends if possible (for demo)
              let recoveryAmount = 0;
              let recoveredCount = 0;

              if (isDemo && daily_trends) {
                // Approximate for demo
                recoveryAmount = Math.floor(
                  revenue_summary.total_recovered /
                    Object.keys(gateway_metrics).length,
                );
                recoveredCount = Math.floor(Math.random() * 50) + 10;
              }

              return React.createElement(
                "tr",
                { key: id },
                React.createElement(
                  "td",
                  { style: { textTransform: "capitalize", fontWeight: "600" } },
                  id,
                ),
                React.createElement(
                  "td",
                  null,
                  React.createElement(
                    "span",
                    {
                      style: {
                        color:
                          data.periods?.["24hour"]?.success_rate > 90
                            ? "#46b450"
                            : "#f39c12",
                        fontWeight: "bold",
                      },
                    },
                    `${(data.periods?.["24hour"]?.success_rate || 0).toFixed(1)}%`,
                  ),
                ),
                React.createElement(
                  "td",
                  null,
                  recoveryAmount > 0
                    ? `$${recoveryAmount.toLocaleString()}`
                    : "Analyzing...",
                ),
                React.createElement(
                  "td",
                  null,
                  recoveredCount > 0 ? recoveredCount : "PRO Tier",
                ),
              );
            }),
          ),
        ),
      ),
    );
  }

  function RecoveryFlow({ summary }) {
    return React.createElement(
      "div",
      {
        style: {
          display: "flex",
          alignItems: "center",
          justifyContent: "space-between",
          padding: "20px",
        },
      },
      React.createElement(FlowStep, {
        label: "Initial Failure",
        count: "100%",
        color: "#666",
      }),
      React.createElement("span", {
        className: "dashicons dashicons-arrow-right-alt2",
        style: { color: "#ccc" },
      }),
      React.createElement(FlowStep, {
        label: "Local Heuristics",
        count: "Analyze",
        color: "#0073aa",
      }),
      React.createElement("span", {
        className: "dashicons dashicons-arrow-right-alt2",
        style: { color: "#ccc" },
      }),
      React.createElement(FlowStep, {
        label: "Smart Retry",
        count: "Execute",
        color: "#f39c12",
      }),
      React.createElement("span", {
        className: "dashicons dashicons-arrow-right-alt2",
        style: { color: "#ccc" },
      }),
      React.createElement(FlowStep, {
        label: "Recovered",
        count: `${((summary.revenue_summary.total_recovered / (summary.revenue_summary.total_recovered + summary.revenue_summary.total_lost)) * 100 || 0).toFixed(1)}%`,
        color: "#46b450",
      }),
    );
  }

  function FlowStep({ label, count, color }) {
    return React.createElement(
      "div",
      { style: { textAlign: "center", flex: 1 } },
      React.createElement(
        "div",
        {
          style: {
            width: "60px",
            height: "60px",
            borderRadius: "50%",
            background: color,
            color: "white",
            display: "flex",
            alignItems: "center",
            justifyContent: "center",
            margin: "0 auto 10px",
            fontWeight: "bold",
          },
        },
        count,
      ),
      React.createElement(
        "div",
        { style: { fontSize: "12px", fontWeight: "500" } },
        label,
      ),
    );
  }

  function AnalyticsCard({ title, value, subtitle, color, onExport }) {
    return React.createElement(
      "div",
      {
        className: "paysentinel-card analytics-stat-card",
        style: {
          flex: 1,
          borderLeft: `5px solid ${color}`,
          position: "relative",
          padding: "20px 20px 20px 30px",
          display: "flex",
          flexDirection: "column",
          justifyContent: "center",
          minHeight: "120px",
          boxShadow: "0 2px 4px rgba(0,0,0,0.05)",
        },
      },
      onExport &&
        React.createElement(
          "button",
          {
            onClick: onExport,
            className: "button button-small",
            style: { position: "absolute", top: "10px", right: "10px" },
            title: "Export Data",
          },
          React.createElement("span", {
            className: "dashicons dashicons-download",
          }),
        ),
      React.createElement(
        "div",
        {
          style: {
            fontSize: "14px",
            color: "#666",
            fontWeight: "600",
            marginBottom: "8px",
            textTransform: "uppercase",
            letterSpacing: "0.5px",
          },
        },
        title,
      ),
      React.createElement(
        "div",
        {
          style: {
            fontSize: "32px",
            fontWeight: "bold",
            color: "#23282d",
            marginBottom: "5px",
            lineHeight: "1.2",
          },
        },
        value,
      ),
      React.createElement(
        "div",
        { style: { fontSize: "13px", color: "#888", fontStyle: "italic" } },
        subtitle,
      ),
    );
  }

  /**
   * Gateway Health Component
   * Displays real-time health metrics for all payment gateways with historical trends
   */
  function GatewayHealth() {
    const [gateways, setGateways] = useState([]);
    const [isLoading, setIsLoading] = useState(false);
    const [error, setError] = useState(null);
    const [timePeriod, setTimePeriod] = useState("24h");
    const [scope, setScope] = useState("enabled");
    const [expandedGateway, setExpandedGateway] = useState(null);
    const [isRecalculating, setIsRecalculating] = useState(false);

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
          `${window.wcPaymentMonitor.apiUrl}/health/gateways?period=${timePeriod}&scope=${scope}`,
          {
            method: "GET",
            headers,
            credentials: "same-origin",
          },
        );

        console.log("Response status:", response.status, response.statusText);

        if (!response.ok) {
          const errorText = await response.text();
          console.error("Response error body:", errorText);
          throw new Error(
            `HTTP error! status: ${response.status} - ${errorText}`,
          );
        }

        const data = await response.json();
        console.log("Gateway health data:", data);

        // Check if the response indicates success
        if (data.success === false) {
          setError(
            data.message || data.code || "Failed to fetch gateway health",
          );
          return;
        }

        const normalized = normalizeGateways(data.data?.items || []);

        setGateways(normalized);
        // Clear any prior error once we have valid data
        setError(null);
      } catch (err) {
        setError(err.message || "Failed to fetch gateway health");
        console.error("Gateway health fetch error:", err);
      } finally {
        setIsLoading(false);
      }
    }, [timePeriod, scope]);

    // Recalculate health data on-demand
    const handleRecalculateNow = async () => {
      setIsRecalculating(true);
      try {
        const nonce = window.wcPaymentMonitor?.nonce || "";
        const headers = {
          "Content-Type": "application/json",
        };
        if (nonce) {
          headers["X-WP-Nonce"] = nonce;
        }

        console.log("Triggering health recalculation...");
        const response = await fetch(
          `${window.wcPaymentMonitor.apiUrl}/health/recalculate`,
          {
            method: "POST",
            headers,
            credentials: "same-origin",
          },
        );

        if (!response.ok) {
          throw new Error(
            `Failed to recalculate: ${response.status} ${response.statusText}`,
          );
        }

        const result = await response.json();
        console.log("Recalculation result:", result);

        // Re-fetch health data after recalc
        await fetchGatewayHealth();
      } catch (err) {
        console.error("Recalculation error:", err);
        setError(err.message || "Failed to recalculate gateway health");
      } finally {
        setIsRecalculating(false);
      }
    };
    useEffect(() => {
      fetchGatewayHealth();

      const intervalId = setInterval(() => {
        fetchGatewayHealth();
      }, 30000); // Refresh every 30 seconds

      return () => clearInterval(intervalId);
    }, [timePeriod, scope]);

    // Get status class based on health percentage
    const getStatusClass = (gateway) => {
      const txCount =
        gateway?.transaction_count ?? gateway?.total_transactions ?? 0;
      if (!txCount) return "status-unknown";
      const health = gateway?.health_percentage ?? 0;
      if (health >= 95) return "status-excellent";
      if (health >= 90) return "status-good";
      if (health >= 75) return "status-warning";
      return "status-critical";
    };

    // Get status text
    const getStatusText = (gateway) => {
      const txCount =
        gateway?.transaction_count ?? gateway?.total_transactions ?? 0;
      if (!txCount) return "No Data";
      const health = gateway?.health_percentage ?? 0;
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

    // Get short period label for stats
    const getPeriodShortLabel = () => {
      const periods = {
        "24h": "24h",
        "7d": "7d",
        "30d": "30d",
      };
      return periods[timePeriod] || "24h";
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
            "Real-time health metrics for all payment gateways",
          ),
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
              style: { height: "32px", padding: "0 8px", lineHeight: "32px" },
            },
            React.createElement("option", { value: "24h" }, "Last 24 Hours"),
            React.createElement("option", { value: "7d" }, "Last 7 Days"),
            React.createElement("option", { value: "30d" }, "Last 30 Days"),
          ),
          React.createElement(
            "label",
            { htmlFor: "gateway-scope", style: { marginLeft: "20px" } },
            "Gateways:",
          ),
          React.createElement(
            "select",
            {
              id: "gateway-scope",
              value: scope,
              onChange: (e) => setScope(e.target.value),
              style: { height: "32px", padding: "0 8px", lineHeight: "32px" },
            },
            React.createElement("option", { value: "enabled" }, "Enabled Only"),
            React.createElement("option", { value: "all" }, "All Registered"),
          ),
          React.createElement(
            "button",
            {
              className: "button button-secondary",
              onClick: handleRecalculateNow,
              disabled: isRecalculating,
              style: { marginLeft: "20px", height: "32px", padding: "0 12px" },
            },
            isRecalculating ? "Recalculating..." : "Recalculate Now",
          ),
        ),
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
            "Loading gateway health data...",
          )
        : null,

      // Empty state
      gateways.length === 0 && !isLoading && !error
        ? React.createElement(
            "div",
            { className: "empty-state" },
            "No gateway data available yet. Please check back soon.",
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
                gateway.gateway_name || gateway.gateway_id,
              ),
              React.createElement(
                "span",
                {
                  className: "status-badge " + getStatusClass(gateway),
                },
                getStatusText(gateway),
              ),
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
                  "Health Score",
                ),
                React.createElement(
                  "span",
                  { className: "stat-value" },
                  gateway.transaction_count === 0
                    ? "N/A"
                    : gateway.health_percentage.toFixed(1) + "%",
                ),
              ),
              React.createElement(
                "div",
                { className: "stat" },
                React.createElement(
                  "span",
                  { className: "stat-label" },
                  "Success Rate (" + getPeriodShortLabel() + ")",
                ),
                React.createElement(
                  "span",
                  { className: "stat-value" },
                  gateway.transaction_count === 0
                    ? "N/A"
                    : gateway.success_rate.toFixed(1) + "%",
                ),
              ),
              React.createElement(
                "div",
                { className: "stat" },
                React.createElement(
                  "span",
                  { className: "stat-label" },
                  "Total Transactions",
                ),
                React.createElement(
                  "span",
                  { className: "stat-value" },
                  gateway.transaction_count,
                ),
              ),
              React.createElement(
                "div",
                { className: "stat" },
                React.createElement(
                  "span",
                  { className: "stat-label" },
                  "Failed (" + getPeriodShortLabel() + ")",
                ),
                React.createElement(
                  "span",
                  { className: "stat-value error" },
                  gateway.failed_transactions,
                ),
              ),
            ),

            // Progress bar
            React.createElement(
              "div",
              { className: "gateway-chart" },
              React.createElement(
                "div",
                { className: "progress-bar" },
                React.createElement("div", {
                  className: "progress-fill " + getStatusClass(gateway),
                  style: {
                    width: gateway.health_percentage + "%",
                  },
                }),
              ),
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
                          isExpanded ? null : gateway.gateway_id,
                        ),
                    },
                    (isExpanded ? "Hide" : "Show") + " Historical Trend",
                  ),

                  // Expanded trend chart
                  isExpanded
                    ? React.createElement(
                        "div",
                        { className: "trend-chart" },
                        React.createElement(
                          "div",
                          { className: "trend-header" },
                          "Historical Trend - " + getTimePeriodLabel(),
                        ),
                        React.createElement(HealthTrendChart, {
                          data: trendData,
                          period: timePeriod,
                          gatewayId: gateway.gateway_id,
                        }),
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
                                  ...trendData.map((p) => p.health_score),
                                ).toFixed(1) +
                                "%",
                            ),
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
                                    0,
                                  ) / trendData.length
                                ).toFixed(1) +
                                "%",
                            ),
                          ),
                          React.createElement(
                            "div",
                            { className: "trend-stat" },
                            React.createElement(
                              "span",
                              null,
                              "Lowest: " +
                                Math.min(
                                  ...trendData.map((p) => p.health_score),
                                ).toFixed(1) +
                                "%",
                            ),
                          ),
                        ),
                      )
                    : null,
                )
              : null,
          );
        }),
      ),
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
    const [expandedTransactionId, setExpandedTransactionId] = useState(null);

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

        let url = `${window.wcPaymentMonitor.apiUrl}/transactions?page=${page}&per_page=${perPage}`;

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
            `HTTP error! status: ${response.status} - ${errorText}`,
          );
        }

        const data = await response.json();
        console.log("Transactions data:", data);

        if (data.success === false) {
          setError(data.message || data.code || "Failed to fetch transactions");
          return;
        }

        setTransactions(data.data?.items || []);
        setTotalCount(data.data?.total || data.data?.items?.length || 0);
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

    // Toggle transaction details expansion
    const toggleTransactionDetails = (transactionId) => {
      setExpandedTransactionId(
        expandedTransactionId === transactionId ? null : transactionId,
      );
    };

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
            "View all monitored payment transactions",
          ),
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
            React.createElement("option", { value: "retry" }, "Retry"),
          ),
        ),
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
            "Loading transactions...",
          )
        : null,

      // Empty state
      transactions.length === 0 && !isLoading && !error
        ? React.createElement(
            "div",
            { className: "empty-state" },
            "No transactions found.",
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
                  React.createElement("th", null, "Date"),
                  React.createElement("th", null, "Actions"),
                ),
              ),
              React.createElement(
                "tbody",
                null,
                transactions.map((tx) =>
                  React.createElement(
                    React.Fragment,
                    { key: tx.id },
                    React.createElement(
                      "tr",
                      {
                        className:
                          "transaction-row status-" + (tx.status || ""),
                      },
                      React.createElement("td", null, tx.id),
                      React.createElement("td", null, tx.order_id || "N/A"),
                      React.createElement(
                        "td",
                        null,
                        tx.gateway_name || tx.gateway_id,
                      ),
                      React.createElement(
                        "td",
                        null,
                        tx.currency
                          ? tx.currency + " " + parseFloat(tx.amount).toFixed(2)
                          : "$" + parseFloat(tx.amount || 0).toFixed(2),
                      ),
                      React.createElement(
                        "td",
                        null,
                        React.createElement(
                          "span",
                          {
                            className:
                              "status-badge status-" + (tx.status || ""),
                          },
                          tx.status || "unknown",
                        ),
                      ),
                      React.createElement(
                        "td",
                        null,
                        tx.created_at
                          ? new Date(tx.created_at).toLocaleString()
                          : "N/A",
                      ),
                      React.createElement(
                        "td",
                        null,
                        React.createElement(
                          "button",
                          {
                            className: "button button-small",
                            onClick: () => toggleTransactionDetails(tx.id),
                          },
                          expandedTransactionId === tx.id ? "Hide" : "Details",
                        ),
                      ),
                    ),
                    expandedTransactionId === tx.id
                      ? React.createElement(
                          "tr",
                          { className: "transaction-details-row" },
                          React.createElement(
                            "td",
                            { colSpan: "7" },
                            React.createElement(
                              "div",
                              { className: "transaction-details" },
                              React.createElement(
                                "div",
                                { className: "detail-grid" },
                                React.createElement(
                                  "div",
                                  { className: "detail-item" },
                                  React.createElement(
                                    "strong",
                                    null,
                                    "Transaction ID: ",
                                  ),
                                  React.createElement(
                                    "span",
                                    null,
                                    tx.transaction_id || "N/A",
                                  ),
                                ),
                                React.createElement(
                                  "div",
                                  { className: "detail-item" },
                                  React.createElement(
                                    "strong",
                                    null,
                                    "Customer Email: ",
                                  ),
                                  React.createElement(
                                    "span",
                                    null,
                                    tx.customer_email || "N/A",
                                  ),
                                ),
                                React.createElement(
                                  "div",
                                  { className: "detail-item" },
                                  React.createElement(
                                    "strong",
                                    null,
                                    "Customer IP: ",
                                  ),
                                  React.createElement(
                                    "span",
                                    null,
                                    tx.customer_ip || "N/A",
                                  ),
                                ),
                                React.createElement(
                                  "div",
                                  { className: "detail-item" },
                                  React.createElement(
                                    "strong",
                                    null,
                                    "Retry Count: ",
                                  ),
                                  React.createElement(
                                    "span",
                                    null,
                                    tx.retry_count || 0,
                                  ),
                                ),
                                tx.status === "failed"
                                  ? React.createElement(
                                      React.Fragment,
                                      null,
                                      React.createElement(
                                        "div",
                                        { className: "detail-item full-width" },
                                        React.createElement(
                                          "strong",
                                          null,
                                          "Failure Code: ",
                                        ),
                                        React.createElement(
                                          "span",
                                          null,
                                          tx.failure_code || "N/A",
                                        ),
                                      ),
                                      React.createElement(
                                        "div",
                                        { className: "detail-item full-width" },
                                        React.createElement(
                                          "strong",
                                          null,
                                          "Failure Reason: ",
                                        ),
                                        React.createElement(
                                          "span",
                                          null,
                                          tx.failure_reason || "N/A",
                                        ),
                                      ),
                                    )
                                  : null,
                                React.createElement(
                                  "div",
                                  { className: "detail-item" },
                                  React.createElement(
                                    "strong",
                                    null,
                                    "Created: ",
                                  ),
                                  React.createElement(
                                    "span",
                                    null,
                                    tx.created_at
                                      ? new Date(tx.created_at).toLocaleString()
                                      : "N/A",
                                  ),
                                ),
                                React.createElement(
                                  "div",
                                  { className: "detail-item" },
                                  React.createElement(
                                    "strong",
                                    null,
                                    "Updated: ",
                                  ),
                                  React.createElement(
                                    "span",
                                    null,
                                    tx.updated_at
                                      ? new Date(tx.updated_at).toLocaleString()
                                      : "N/A",
                                  ),
                                ),
                              ),
                            ),
                          ),
                        )
                      : null,
                  ),
                ),
              ),
            ),
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
              "Previous",
            ),
            React.createElement(
              "span",
              { className: "page-info" },
              "Page " + page + " of " + totalPages,
            ),
            React.createElement(
              "button",
              {
                disabled: page >= totalPages,
                onClick: () => setPage(page + 1),
              },
              "Next",
            ),
          )
        : null,
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

      const url =
        `${window.wcPaymentMonitor.apiUrl}/alerts?` + params.toString();

      try {
        setIsLoading(true);
        setError(null);

        console.log("Fetching alerts from:", url);

        fetch(url, {
          method: "GET",
          headers,
          credentials: "same-origin",
        })
          .then(async (response) => {
            console.log("Alerts response status:", response.status);

            if (!response.ok) {
              let errorDetail = "";
              try {
                const errorData = await response.json();
                errorDetail =
                  errorData.message || errorData.error || errorData.code || "";
              } catch (e) {
                try {
                  errorDetail = await response.text();
                  // Truncate HTML if necessary
                  if (errorDetail.includes("<html")) {
                    errorDetail = "HTML error response (see console)";
                  }
                } catch (e2) {
                  errorDetail = response.statusText;
                }
              }

              throw new Error(
                `HTTP error! status: ${response.status}${
                  errorDetail ? " - " + errorDetail : ""
                }`,
              );
            }

            return response.json();
          })
          .then((data) => {
            console.log("Alerts data:", data);

            if (data.success === false) {
              setError(data.message || data.code || "Failed to fetch alerts");
              return;
            }

            setAlerts(data.data?.items || []);
            setTotalCount(data.data?.total || data.data?.items?.length || 0);
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

      fetch(`${window.wcPaymentMonitor.apiUrl}/alerts/${alertId}/resolve`, {
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
        "Error: " + error,
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
            "View all payment monitoring alerts",
          ),
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
            React.createElement("option", { value: "critical" }, "Critical"),
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
            React.createElement("option", { value: "resolved" }, "Resolved"),
          ),
        ),
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
            "Loading alerts...",
          )
        : null,

      // Empty state
      alerts.length === 0 && !isLoading && !error
        ? React.createElement(
            "div",
            { className: "empty-state" },
            "No alerts found.",
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
                    alert.message,
                  ),
                ),

                React.createElement(
                  "div",
                  { className: "alert-cell alert-gateway-cell" },
                  alert.gateway_name || alert.gateway_id || "—",
                ),

                React.createElement(
                  "div",
                  { className: "alert-cell alert-severity-cell" },
                  React.createElement(
                    "span",
                    {
                      className: "severity-badge " + (alert.severity || "info"),
                    },
                    alert.severity?.toUpperCase() || "INFO",
                  ),
                ),

                React.createElement(
                  "div",
                  { className: "alert-cell alert-status-cell" },
                  React.createElement(
                    "span",
                    {
                      className: "status-badge " + (alert.status || "active"),
                    },
                    alert.status?.toUpperCase() || "ACTIVE",
                  ),
                ),

                React.createElement(
                  "div",
                  { className: "alert-cell alert-date-cell" },
                  alert.created_at
                    ? new Date(alert.created_at).toLocaleDateString() +
                        " " +
                        new Date(alert.created_at).toLocaleTimeString()
                    : "—",
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
                        "Resolve",
                      ),
                    )
                  : null,
              ),
            ),
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
              "← Previous",
            ),
            React.createElement(
              "span",
              { className: "page-info" },
              "Page " + page + " of " + totalPages,
            ),
            React.createElement(
              "button",
              {
                className: "button",
                disabled: page >= totalPages,
                onClick: () => setPage(page + 1),
              },
              "Next →",
            ),
          )
        : null,
    );
  }
})(window.wp, window.React, window.ReactDOM);
