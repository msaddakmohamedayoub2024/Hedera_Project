import { useState } from "react";
import api from "./Api";

export default function HackerDemoButton({ onVerified }) {
  const [busy, setBusy] = useState(false);
  const [message, setMessage] = useState("");
  const [reportReference, setReportReference] = useState("");
  const [isError, setIsError] = useState(false);

  const simulate = async () => {
    setBusy(true);
    setIsError(false);
    setMessage("");
    setReportReference("");
    try {
      const { data } = await api.post("/demo/simulate-hacker");
      setMessage(data.message);
      setReportReference(data.reportReference ?? "");
      await onVerified();
    } catch (error) {
      setIsError(true);
      setMessage(error.response?.data?.message || error.message);
    } finally {
      setBusy(false);
    }
  };

  const reset = async () => {
    setBusy(true);
    setIsError(false);
    setMessage("");
    setReportReference("");
    try {
      const { data } = await api.post("/demo/reset");
      setMessage(data.message);
      await onVerified();
    } catch (error) {
      setIsError(true);
      setMessage(error.response?.data?.message || error.message);
    } finally {
      setBusy(false);
    }
  };

  return (
    <section className="ins-section">
      <div className="ins-print-actions">
        <button
          className="ins-btn"
          type="button"
          disabled={busy}
          onClick={simulate}
          style={{ borderColor: "#dc3232", color: "#ffd0d0" }}
        >
          {busy ? "Simulation en cours…" : "Se comporter comme un hacker"}
        </button>
        <button
          type="button"
          disabled={busy}
          onClick={reset}
          style={{
            background: "transparent",
            border: 0,
            color: "var(--text)",
            cursor: busy ? "wait" : "pointer",
            font: "inherit",
            textDecoration: "underline",
          }}
        >
          Réinitialiser la démo
        </button>
      </div>
      {message && (
        <p className={isError ? "ins-admin-error" : "ins-text"} role={isError ? "alert" : "status"}>
          {message}
          {reportReference && <> Référence : {reportReference}.</>}
        </p>
      )}
    </section>
  );
}
