import React from "react";
import ReactDOM from "react-dom/client";
import App from "./App";
import "./index.css";

/**
 * Payment Monitor Dashboard Entry Point
 *
 * Initializes the React application and mounts it to the dashboard container
 */
const container = document.getElementById("wc-payment-monitor-root");

if (container) {
  const root = ReactDOM.createRoot(container);
  root.render(
    <React.StrictMode>
      <App />
    </React.StrictMode>
  );
}
