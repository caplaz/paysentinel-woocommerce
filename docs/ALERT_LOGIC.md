# Alert Severity Logic

The WooCommerce Payment Monitor uses a sophisticated hybrid approach to alerting, combining immediate triggers with statistical analysis while remaining sensitive to transaction volume to minimize noise.

## 1. Immediate Alerts (Critical)

Certain failures are flagged as **Critical** immediately when they occur. These are "System Errors" that indicate a configuration or connectivity problem rather than a user error (like a declined card).

The system scans the failure reason for keywords including:

- `authentication_required` (Gateway Misconfiguration)
- `connection refused` (Connection Error)
- `timed out` (Gateway Timeout)
- `api key` (Invalid API Key)
- `unauthorized` (Unauthorized Access)
- `curl error` (Network Error)
- `service unavailable` (Service Unavailable)

## 2. Statistical Alerts

The system performs periodic health checks (via Action Scheduler) across multiple time periods (1h, 24h, 7d). Alerts are triggered when the **Success Rate** drops below defined thresholds.

### Success Rate Thresholds

- **High**: < 75% success (25%+ failure rate)
- **Warning**: 75-89% success (11-25% failure rate)
- **Info**: 90-94% success (6-10% failure rate)

## 3. Volume-Aware Severity Adjustment

To avoid triggering high-severity alerts for random coincidences in low-volume stores, the system adjusts the severity based on the number of transactions in the analyzed period.

| Transaction Volume | Maximum Allowed Severity | Logic                                                                         |
| :----------------- | :----------------------- | :---------------------------------------------------------------------------- |
| **Micro (1-2)**    | **Info**                 | Sample size too small for statistical significance. Always informational.     |
| **Low (3-9)**      | **Warning**              | Moderate suspicion. Capped at Warning even if success rate is 0%.             |
| **Standard (10+)** | **Full Severity**        | Statistically significant. Thresholds (High/Warning/Info) applied as defined. |

### Example Scenario

- **Scenario A**: 1 transaction, 1 failure (0% success).
  - _Result_: **Info Alert**. "Success rate dropped to 0%, but volume is micro."
- **Scenario B**: 15 transactions, 15 failures (0% success).
  - _Result_: **High Alert**. "Success rate dropped to 0% on 15 transactions."

## 4. Rate Limiting

To prevent alert fatigue, each gateway/type combination is rate-limited:

- **Alert Frequency**: Maximum one alert per type per gateway every **1 hour**.
- **Resolution**: Alerts are automatically resolved if the gateway's health returns above the `Info` threshold (95%+) in a subsequent check.
