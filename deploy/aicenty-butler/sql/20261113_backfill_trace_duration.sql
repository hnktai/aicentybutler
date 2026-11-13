UPDATE traces
SET duration_ms = GREATEST(
    0,
    FLOOR(EXTRACT(EPOCH FROM (end_time - start_time)) * 1000)::integer
)
WHERE end_time IS NOT NULL
  AND duration_ms IS NULL;
