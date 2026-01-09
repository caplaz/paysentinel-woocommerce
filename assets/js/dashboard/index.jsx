import React from "react";
import ReactDOM from "react-dom/client";
import App from "./App";
import GatewayHealthComponent from "./components/GatewayHealth";
import "./index.css";

/**
 * Payment Monitor Dashboard Entry Point
 *
 * Initializes the React application and mounts it to the dashboard container
 */
const dashboardContainer = document.getElementById("wc-payment-monitor-root");

if (dashboardContainer) {
  const root = ReactDOM.createRoot(dashboardContainer);
  root.render(
    <React.StrictMode>
      <App />
    </React.StrictMode>
  );
}

/**
 * Gateway Health Page Entry Point
 *
 * Mounts the GatewayHealth component when accessed via the Health page
 */
const healthContainer = document.getElementById(
  "wc-payment-monitor-health-container"
);

if (healthContainer) {
  const root = ReactDOM.createRoot(healthContainer);
  root.render(
    <React.StrictMode>
      <GatewayHealthComponent
        refreshInterval={30}
        onRefresh={() => {}}
        onLoadingChange={() => {}}
      />
    </React.StrictMode>
  );
}
