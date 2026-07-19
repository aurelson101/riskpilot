import {
  AdminPanelSettingsOutlined,
  AccountTreeOutlined,
  Inventory2Outlined,
  BugReportOutlined,
  GppMaybeOutlined,
  DashboardOutlined,
  Logout,
  ShieldOutlined,
  AssessmentOutlined,
  GridViewOutlined,
  VerifiedUserOutlined,
  TaskAltOutlined,
  NotificationsOutlined,
  FactCheckOutlined,
} from "@mui/icons-material";
import {
  AppBar,
  Avatar,
  Box,
  Button,
  CircularProgress,
  Container,
  Drawer,
  List,
  ListItemButton,
  ListItemIcon,
  ListItemText,
  Stack,
  Toolbar,
  Typography,
} from "@mui/material";
import {
  Navigate,
  Outlet,
  Route,
  Routes,
  useLocation,
  useNavigate,
} from "react-router-dom";
import { useAuth } from "./auth/useAuth";
import { LoginPage } from "./pages/LoginPage";
import { InventoryPage } from "./pages/InventoryPage";
import { UsersPage } from "./pages/UsersPage";
import { RisksPage } from "./pages/RisksPage";
import { RiskMatrixPage } from "./pages/RiskMatrixPage";
import { ActionsPage } from "./pages/ActionsPage";
import { NotificationsPage } from "./pages/NotificationsPage";
import { CompliancePage } from "./pages/CompliancePage";

const drawerWidth = 250;

function ProtectedRoute() {
  return useAuth().token ? <Outlet /> : <Navigate to="/login" replace />;
}

function Layout() {
  const { user, logout } = useAuth();
  const navigate = useNavigate();
  const location = useLocation();
  const isAdmin = user?.roles.some((role) =>
    ["ROLE_ADMIN", "ROLE_SUPER_ADMIN"].includes(role),
  );

  if (!user) {
    return (
      <Stack minHeight="100vh" alignItems="center" justifyContent="center">
        <CircularProgress aria-label="Chargement du profil" />
      </Stack>
    );
  }

  return (
    <Box sx={{ display: "flex", minHeight: "100vh", bgcolor: "#f4f7fb" }}>
      <AppBar
        position="fixed"
        color="inherit"
        elevation={0}
        sx={{ ml: `${drawerWidth}px`, width: `calc(100% - ${drawerWidth}px)` }}
      >
        <Toolbar sx={{ borderBottom: "1px solid #e5eaf1" }}>
          <Typography variant="h6" sx={{ flexGrow: 1 }}>
            {location.pathname.startsWith("/administration")
              ? "Administration"
              : "Tableau de bord"}
          </Typography>
          <Stack direction="row" spacing={1.5} alignItems="center">
            <Avatar sx={{ bgcolor: "#1769e0", width: 34, height: 34 }}>
              {user.firstName[0]}
              {user.lastName[0]}
            </Avatar>
            <Box>
              <Typography variant="body2" fontWeight={700}>
                {user.firstName} {user.lastName}
              </Typography>
              <Typography variant="caption" color="text.secondary">
                {user.organization.name}
              </Typography>
            </Box>
          </Stack>
        </Toolbar>
      </AppBar>
      <Drawer
        variant="permanent"
        sx={{
          width: drawerWidth,
          "& .MuiDrawer-paper": {
            width: drawerWidth,
            bgcolor: "#062b4b",
            color: "white",
          },
        }}
      >
        <Toolbar>
          <ShieldOutlined sx={{ mr: 1.5, color: "#54a3ff" }} />
          <Typography variant="h5" fontWeight={750}>
            RiskPilot
          </Typography>
        </Toolbar>
        <List sx={{ px: 1 }}>
          <ListItemButton
            selected={location.pathname === "/"}
            onClick={() => navigate("/")}
          >
            <ListItemIcon>
              <DashboardOutlined sx={{ color: "inherit" }} />
            </ListItemIcon>
            <ListItemText primary="Tableau de bord" />
          </ListItemButton>
          <ListItemButton
            selected={location.pathname === "/risks"}
            onClick={() => navigate("/risks")}
          >
            <ListItemIcon>
              <AssessmentOutlined sx={{ color: "inherit" }} />
            </ListItemIcon>
            <ListItemText primary="Risques" />
          </ListItemButton>
          <ListItemButton
            selected={location.pathname === "/actions"}
            onClick={() => navigate("/actions")}
          >
            <ListItemIcon>
              <TaskAltOutlined sx={{ color: "inherit" }} />
            </ListItemIcon>
            <ListItemText primary="Plans d’action" />
          </ListItemButton>
          <ListItemButton
            selected={location.pathname === "/risk-matrix"}
            onClick={() => navigate("/risk-matrix")}
          >
            <ListItemIcon>
              <GridViewOutlined sx={{ color: "inherit" }} />
            </ListItemIcon>
            <ListItemText primary="Matrice des risques" />
          </ListItemButton>
          <ListItemButton
            selected={location.pathname === "/scopes"}
            onClick={() => navigate("/scopes")}
          >
            <ListItemIcon>
              <AccountTreeOutlined sx={{ color: "inherit" }} />
            </ListItemIcon>
            <ListItemText primary="Périmètres" />
          </ListItemButton>
          <ListItemButton
            selected={location.pathname === "/assets"}
            onClick={() => navigate("/assets")}
          >
            <ListItemIcon>
              <Inventory2Outlined sx={{ color: "inherit" }} />
            </ListItemIcon>
            <ListItemText primary="Actifs" />
          </ListItemButton>
          <ListItemButton
            selected={location.pathname === "/threats"}
            onClick={() => navigate("/threats")}
          >
            <ListItemIcon>
              <GppMaybeOutlined sx={{ color: "inherit" }} />
            </ListItemIcon>
            <ListItemText primary="Menaces" />
          </ListItemButton>
          <ListItemButton
            selected={location.pathname === "/compliance"}
            onClick={() => navigate("/compliance")}
          >
            <ListItemIcon>
              <FactCheckOutlined sx={{ color: "inherit" }} />
            </ListItemIcon>
            <ListItemText primary="Conformité" />
          </ListItemButton>
          <ListItemButton
            selected={location.pathname === "/security-controls"}
            onClick={() => navigate("/security-controls")}
          >
            <ListItemIcon>
              <VerifiedUserOutlined sx={{ color: "inherit" }} />
            </ListItemIcon>
            <ListItemText primary="Mesures de sécurité" />
          </ListItemButton>
          <ListItemButton
            selected={location.pathname === "/vulnerabilities"}
            onClick={() => navigate("/vulnerabilities")}
          >
            <ListItemIcon>
              <BugReportOutlined sx={{ color: "inherit" }} />
            </ListItemIcon>
            <ListItemText primary="Vulnérabilités" />
          </ListItemButton>
          {isAdmin && (
            <ListItemButton
              selected={location.pathname === "/administration/users"}
              onClick={() => navigate("/administration/users")}
            >
              <ListItemIcon>
                <AdminPanelSettingsOutlined sx={{ color: "inherit" }} />
              </ListItemIcon>
              <ListItemText primary="Utilisateurs" />
            </ListItemButton>
          )}
          <ListItemButton
            selected={location.pathname === "/notifications"}
            onClick={() => navigate("/notifications")}
          >
            <ListItemIcon>
              <NotificationsOutlined sx={{ color: "inherit" }} />
            </ListItemIcon>
            <ListItemText primary="Notifications" />
          </ListItemButton>
        </List>
        <Box sx={{ mt: "auto", p: 2 }}>
          <Button
            color="inherit"
            fullWidth
            startIcon={<Logout />}
            onClick={logout}
          >
            Déconnexion
          </Button>
        </Box>
      </Drawer>
      <Box component="main" sx={{ flexGrow: 1, pt: 11, pb: 5 }}>
        <Container maxWidth="xl">
          <Outlet />
        </Container>
      </Box>
    </Box>
  );
}

function DashboardPage() {
  const { user } = useAuth();
  return (
    <Stack spacing={1}>
      <Typography variant="h4" fontWeight={750}>
        Bonjour {user?.firstName}
      </Typography>
      <Typography color="text.secondary">
        Votre espace sécurisé {user?.organization.name} est prêt.
      </Typography>
    </Stack>
  );
}

export default function App() {
  return (
    <Routes>
      <Route path="/login" element={<LoginPage />} />
      <Route element={<ProtectedRoute />}>
        <Route element={<Layout />}>
          <Route index element={<DashboardPage />} />
          <Route path="risks" element={<RisksPage />} />
          <Route path="actions" element={<ActionsPage />} />
          <Route path="notifications" element={<NotificationsPage />} />
          <Route path="compliance" element={<CompliancePage />} />
          <Route path="risk-matrix" element={<RiskMatrixPage />} />
          <Route path="scopes" element={<InventoryPage kind="scopes" />} />
          <Route path="assets" element={<InventoryPage kind="assets" />} />
          <Route path="threats" element={<InventoryPage kind="threats" />} />
          <Route
            path="vulnerabilities"
            element={<InventoryPage kind="vulnerabilities" />}
          />
          <Route
            path="security-controls"
            element={<InventoryPage kind="security-controls" />}
          />
          <Route path="administration/users" element={<UsersPage />} />
        </Route>
      </Route>
      <Route path="*" element={<Navigate to="/" replace />} />
    </Routes>
  );
}
