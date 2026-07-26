import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { AddOutlined } from "@mui/icons-material";
import {
  Alert,
  Button,
  Card,
  CardContent,
  Dialog,
  DialogActions,
  DialogContent,
  DialogTitle,
  MenuItem,
  Stack,
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableRow,
  TextField,
  Typography,
} from "@mui/material";
import { useState, type FormEvent } from "react";
import { api } from "../api/client";
import type { Indicator } from "../api/types";

const empty = {
  code: "",
  name: "",
  kind: "KPI",
  unit: "%",
  frequency: "MONTHLY",
  target: "",
};

export function IndicatorsPage() {
  const client = useQueryClient();
  const [open, setOpen] = useState(false);
  const [form, setForm] = useState(empty);
  const [valueFor, setValueFor] = useState<Indicator | null>(null);
  const [measurement, setMeasurement] = useState({
    value: "",
    measuredAt: new Date().toISOString().slice(0, 16),
    comment: "",
  });
  const indicators = useQuery({
    queryKey: ["indicators"],
    queryFn: async () => (await api.get<Indicator[]>("/v1/indicators")).data,
  });
  const create = useMutation({
    mutationFn: () =>
      api.post("/v1/indicators", {
        ...form,
        target: form.target === "" ? null : Number(form.target),
      }),
    onSuccess: async () => {
      await client.invalidateQueries({ queryKey: ["indicators"] });
      setOpen(false);
      setForm(empty);
    },
  });
  const record = useMutation({
    mutationFn: () =>
      api.post(`/v1/indicators/${valueFor?.id}/values`, {
        ...measurement,
        value: Number(measurement.value),
        measuredAt: new Date(measurement.measuredAt).toISOString(),
        idempotencyKey: crypto.randomUUID(),
      }),
    onSuccess: () => {
      setValueFor(null);
      setMeasurement({
        value: "",
        measuredAt: new Date().toISOString().slice(0, 16),
        comment: "",
      });
    },
  });
  const submit = (event: FormEvent) => {
    event.preventDefault();
    create.mutate();
  };

  return (
    <Stack spacing={3}>
      <Stack
        direction={{ xs: "column", sm: "row" }}
        justifyContent="space-between"
        gap={2}
      >
        <div>
          <Typography variant="h4" fontWeight={800}>
            Indicators
          </Typography>
          <Typography color="text.secondary">
            KPI and KRI definitions, targets and time-series measurements
          </Typography>
        </div>
        <Button
          variant="contained"
          startIcon={<AddOutlined />}
          onClick={() => setOpen(true)}
        >
          New indicator
        </Button>
      </Stack>
      {(indicators.isError || create.isError || record.isError) && (
        <Alert severity="error">The operation could not be completed.</Alert>
      )}
      <Card>
        <CardContent>
          <Table aria-label="Indicators">
            <TableHead>
              <TableRow>
                <TableCell>Code</TableCell>
                <TableCell>Indicator</TableCell>
                <TableCell>Kind</TableCell>
                <TableCell>Frequency</TableCell>
                <TableCell>Target</TableCell>
                <TableCell />
              </TableRow>
            </TableHead>
            <TableBody>
              {indicators.data?.map((item) => (
                <TableRow key={item.id}>
                  <TableCell>{item.code}</TableCell>
                  <TableCell>
                    <Typography fontWeight={700}>{item.name}</Typography>
                    <Typography variant="caption">{item.unit}</Typography>
                  </TableCell>
                  <TableCell>{item.kind}</TableCell>
                  <TableCell>{item.frequency}</TableCell>
                  <TableCell>
                    {item.target ?? "—"} {item.unit}
                  </TableCell>
                  <TableCell align="right">
                    <Button onClick={() => setValueFor(item)}>
                      Record value
                    </Button>
                  </TableCell>
                </TableRow>
              ))}
            </TableBody>
          </Table>
        </CardContent>
      </Card>
      <Dialog open={open} onClose={() => setOpen(false)} fullWidth>
        <form onSubmit={submit}>
          <DialogTitle>New indicator</DialogTitle>
          <DialogContent>
            <Stack spacing={2} mt={1}>
              <TextField
                required
                label="Code"
                value={form.code}
                onChange={(e) => setForm({ ...form, code: e.target.value })}
              />
              <TextField
                required
                label="Name"
                value={form.name}
                onChange={(e) => setForm({ ...form, name: e.target.value })}
              />
              <TextField
                select
                label="Kind"
                value={form.kind}
                onChange={(e) => setForm({ ...form, kind: e.target.value })}
              >
                {["KPI", "KRI"].map((v) => (
                  <MenuItem key={v} value={v}>
                    {v}
                  </MenuItem>
                ))}
              </TextField>
              <TextField
                required
                label="Unit"
                value={form.unit}
                onChange={(e) => setForm({ ...form, unit: e.target.value })}
              />
              <TextField
                select
                label="Frequency"
                value={form.frequency}
                onChange={(e) =>
                  setForm({ ...form, frequency: e.target.value })
                }
              >
                {[
                  "DAILY",
                  "WEEKLY",
                  "MONTHLY",
                  "QUARTERLY",
                  "YEARLY",
                  "ON_DEMAND",
                ].map((v) => (
                  <MenuItem key={v} value={v}>
                    {v}
                  </MenuItem>
                ))}
              </TextField>
              <TextField
                type="number"
                label="Target"
                value={form.target}
                onChange={(e) => setForm({ ...form, target: e.target.value })}
              />
            </Stack>
          </DialogContent>
          <DialogActions>
            <Button onClick={() => setOpen(false)}>Cancel</Button>
            <Button type="submit" variant="contained">
              Create
            </Button>
          </DialogActions>
        </form>
      </Dialog>
      <Dialog
        open={Boolean(valueFor)}
        onClose={() => setValueFor(null)}
        fullWidth
      >
        <DialogTitle>Record value — {valueFor?.name}</DialogTitle>
        <DialogContent>
          <Stack spacing={2} mt={1}>
            <TextField
              required
              type="number"
              label={`Value (${valueFor?.unit ?? ""})`}
              value={measurement.value}
              onChange={(e) =>
                setMeasurement({ ...measurement, value: e.target.value })
              }
            />
            <TextField
              required
              type="datetime-local"
              label="Measured at"
              InputLabelProps={{ shrink: true }}
              value={measurement.measuredAt}
              onChange={(e) =>
                setMeasurement({ ...measurement, measuredAt: e.target.value })
              }
            />
            <TextField
              multiline
              label="Comment"
              value={measurement.comment}
              onChange={(e) =>
                setMeasurement({ ...measurement, comment: e.target.value })
              }
            />
          </Stack>
        </DialogContent>
        <DialogActions>
          <Button onClick={() => setValueFor(null)}>Cancel</Button>
          <Button
            variant="contained"
            disabled={!measurement.value}
            onClick={() => record.mutate()}
          >
            Record
          </Button>
        </DialogActions>
      </Dialog>
    </Stack>
  );
}
