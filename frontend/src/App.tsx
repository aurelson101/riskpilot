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
  AccountCircleOutlined,
  BusinessOutlined,
  HistoryOutlined,
  DescriptionOutlined,
  SettingsOutlined,
  MenuOutlined,
  ChevronLeftOutlined,
  ChevronRightOutlined,
  ExpandLess,
  ExpandMore,
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
  Collapse,
  Divider,
  IconButton,
  Tooltip,
  useMediaQuery,
} from "@mui/material";
import { useTheme } from "@mui/material/styles";
import {
  Navigate,
  Outlet,
  Route,
  Routes,
  useLocation,
  useNavigate,
} from "react-router-dom";
import { useAuth } from "./auth/useAuth";
import { lazy, Suspense, useEffect, useState, type ReactNode } from "react";

const LoginPage = lazy(() =>
  import("./pages/LoginPage").then((module) => ({ default: module.LoginPage })),
);
const InventoryPage = lazy(() =>
  import("./pages/InventoryPage").then((module) => ({
    default: module.InventoryPage,
  })),
);
const UsersPage = lazy(() =>
  import("./pages/UsersPage").then((module) => ({ default: module.UsersPage })),
);
const RisksPage = lazy(() =>
  import("./pages/RisksPage").then((module) => ({ default: module.RisksPage })),
);
const RiskMatrixPage = lazy(() =>
  import("./pages/RiskMatrixPage").then((module) => ({
    default: module.RiskMatrixPage,
  })),
);
const ActionsPage = lazy(() =>
  import("./pages/ActionsPage").then((module) => ({
    default: module.ActionsPage,
  })),
);
const NotificationsPage = lazy(() =>
  import("./pages/NotificationsPage").then((module) => ({
    default: module.NotificationsPage,
  })),
);
const CompliancePage = lazy(() =>
  import("./pages/CompliancePage").then((module) => ({
    default: module.CompliancePage,
  })),
);
const DashboardPage = lazy(() =>
  import("./pages/DashboardPage").then((module) => ({
    default: module.DashboardPage,
  })),
);
const ProfilePage = lazy(() =>
  import("./pages/ProfilePage").then((module) => ({
    default: module.ProfilePage,
  })),
);
const OrganizationsPage = lazy(() =>
  import("./pages/OrganizationsPage").then((module) => ({
    default: module.OrganizationsPage,
  })),
);
const AuditLogsPage = lazy(() =>
  import("./pages/AuditLogsPage").then((module) => ({
    default: module.AuditLogsPage,
  })),
);
const ExecutiveReportPage = lazy(() =>
  import("./pages/ExecutiveReportPage").then((module) => ({
    default: module.ExecutiveReportPage,
  })),
);
const EmailSettingsPage = lazy(() =>
  import("./pages/EmailSettingsPage").then((module) => ({
    default: module.EmailSettingsPage,
  })),
);

const drawerWidth = 264;
const collapsedDrawerWidth = 76;

function ProtectedRoute() {
  return useAuth().token ? <Outlet /> : <Navigate to="/login" replace />;
}

function Layout() {
  const { user, logout } = useAuth();
  const navigate = useNavigate();
  const location = useLocation();
  const theme = useTheme();
  const mobile = useMediaQuery(theme.breakpoints.down("md"));
  const [mobileOpen, setMobileOpen] = useState(false);
  const [collapsed, setCollapsed] = useState(false);
  const settingsActive =
    location.pathname === "/profile" ||
    location.pathname.startsWith("/administration");
  const [settingsOpen, setSettingsOpen] = useState(settingsActive);
  const isAdmin = user?.roles.some((role) =>
    ["ROLE_ADMIN", "ROLE_SUPER_ADMIN"].includes(role),
  );
  const currentDrawerWidth = collapsed ? collapsedDrawerWidth : drawerWidth;

  useEffect(() => {
    if (settingsActive) setSettingsOpen(true);
  }, [settingsActive]);

  if (!user) {
    return (
      <Stack minHeight="100vh" alignItems="center" justifyContent="center">
        <CircularProgress aria-label="Chargement du profil" />
      </Stack>
    );
  }

  const go = (path: string) => {
    navigate(path);
    if (mobile) setMobileOpen(false);
  };
  const titleByPath: Record<string, string> = {
    "/": "Tableau de bord",
    "/risks": "Risques",
    "/actions": "Plans d’action",
    "/risk-matrix": "Matrice des risques",
    "/scopes": "Périmètres",
    "/assets": "Actifs",
    "/threats": "Menaces",
    "/compliance": "Conformité",
    "/security-controls": "Mesures de sécurité",
    "/vulnerabilities": "Vulnérabilités",
    "/notifications": "Notifications",
    "/reports/executive": "Rapport exécutif",
    "/profile": "Mon profil",
    "/administration/users": "Utilisateurs",
    "/administration/organizations": "Organisations",
    "/administration/audit-logs": "Journal d’audit",
    "/administration/email-settings": "Paramètres email",
  };

  function NavItem({
    path,
    label,
    icon,
    nested = false,
  }: {
    path: string;
    label: string;
    icon: ReactNode;
    nested?: boolean;
  }) {
    const button = (
      <ListItemButton
        selected={location.pathname === path}
        onClick={() => go(path)}
        sx={{
          minHeight: 44,
          borderRadius: 1.5,
          mb: 0.25,
          pl: nested && !collapsed ? 3 : 2,
          justifyContent: collapsed ? "center" : "initial",
        }}
      >
        <ListItemIcon
          sx={{
            minWidth: collapsed ? 0 : 42,
            color: "inherit",
            justifyContent: "center",
          }}
        >
          {icon}
        </ListItemIcon>
        {!collapsed && (
          <ListItemText
            primary={label}
            primaryTypographyProps={{ fontSize: nested ? 13.5 : 14 }}
          />
        )}
      </ListItemButton>
    );
    return collapsed && !mobile ? (
      <Tooltip key={path} title={label} placement="right">
        {button}
      </Tooltip>
    ) : (
      button
    );
  }

  const drawerContent = (
    <Box
      sx={{
        height: "100%",
        display: "flex",
        flexDirection: "column",
        overflow: "hidden",
      }}
    >
      <Toolbar
        sx={{
          px: collapsed ? 2 : 2.5,
          justifyContent: collapsed ? "center" : "flex-start",
          flexShrink: 0,
        }}
      >
        <ShieldOutlined
          sx={{ color: "#54a3ff", mr: collapsed ? 0 : 1.5, fontSize: 32 }}
        />
        {!collapsed && (
          <Typography variant="h5" fontWeight={750}>
            RiskPilot
          </Typography>
        )}
      </Toolbar>
      <List sx={{ px: 1, py: 1, overflowY: "auto", overflowX: "hidden" }}>
        <NavItem
          path="/"
          label="Tableau de bord"
          icon={<DashboardOutlined />}
        />
        <NavItem path="/risks" label="Risques" icon={<AssessmentOutlined />} />
        <NavItem
          path="/actions"
          label="Plans d’action"
          icon={<TaskAltOutlined />}
        />
        <NavItem
          path="/risk-matrix"
          label="Matrice des risques"
          icon={<GridViewOutlined />}
        />
        <Divider sx={{ my: 1, borderColor: "rgba(255,255,255,.12)" }} />
        <NavItem
          path="/scopes"
          label="Périmètres"
          icon={<AccountTreeOutlined />}
        />
        <NavItem path="/assets" label="Actifs" icon={<Inventory2Outlined />} />
        <NavItem path="/threats" label="Menaces" icon={<GppMaybeOutlined />} />
        <NavItem
          path="/vulnerabilities"
          label="Vulnérabilités"
          icon={<BugReportOutlined />}
        />
        <NavItem
          path="/security-controls"
          label="Mesures de sécurité"
          icon={<VerifiedUserOutlined />}
        />
        <NavItem
          path="/compliance"
          label="Conformité"
          icon={<FactCheckOutlined />}
        />
        <Divider sx={{ my: 1, borderColor: "rgba(255,255,255,.12)" }} />
        <NavItem
          path="/notifications"
          label="Notifications"
          icon={<NotificationsOutlined />}
        />
        <NavItem
          path="/reports/executive"
          label="Rapport exécutif"
          icon={<DescriptionOutlined />}
        />
        <Tooltip
          title={collapsed && !mobile ? "Paramètres" : ""}
          placement="right"
        >
          <ListItemButton
            selected={settingsActive}
            onClick={() => {
              if (collapsed) {
                setCollapsed(false);
                setSettingsOpen(true);
              } else setSettingsOpen((open) => !open);
            }}
            sx={{
              minHeight: 44,
              borderRadius: 1.5,
              justifyContent: collapsed ? "center" : "initial",
            }}
          >
            <ListItemIcon
              sx={{
                minWidth: collapsed ? 0 : 42,
                color: "inherit",
                justifyContent: "center",
              }}
            >
              <SettingsOutlined />
            </ListItemIcon>
            {!collapsed && (
              <>
                <ListItemText
                  primary="Paramètres"
                  primaryTypographyProps={{ fontSize: 14 }}
                />
                {settingsOpen ? <ExpandLess /> : <ExpandMore />}
              </>
            )}
          </ListItemButton>
        </Tooltip>
        {!collapsed && (
          <Collapse in={settingsOpen} timeout="auto" unmountOnExit>
            <List disablePadding>
              <NavItem
                nested
                path="/profile"
                label="Mon profil et MFA"
                icon={<AccountCircleOutlined fontSize="small" />}
              />
              {isAdmin && (
                <NavItem
                  nested
                  path="/administration/email-settings"
                  label="Messagerie"
                  icon={<SettingsOutlined fontSize="small" />}
                />
              )}
              {isAdmin && (
                <NavItem
                  nested
                  path="/administration/users"
                  label="Utilisateurs"
                  icon={<AdminPanelSettingsOutlined fontSize="small" />}
                />
              )}
              {isAdmin && (
                <NavItem
                  nested
                  path="/administration/organizations"
                  label="Organisations"
                  icon={<BusinessOutlined fontSize="small" />}
                />
              )}
              {isAdmin && (
                <NavItem
                  nested
                  path="/administration/audit-logs"
                  label="Journal d’audit"
                  icon={<HistoryOutlined fontSize="small" />}
                />
              )}
            </List>
          </Collapse>
        )}
      </List>
      <Box
        sx={{
          mt: "auto",
          p: 1,
          borderTop: "1px solid rgba(255,255,255,.12)",
          flexShrink: 0,
        }}
      >
        <Tooltip title={collapsed ? "Déconnexion" : ""} placement="right">
          <Button
            color="inherit"
            fullWidth
            startIcon={<Logout />}
            onClick={logout}
            sx={{
              justifyContent: collapsed ? "center" : "flex-start",
              minWidth: 0,
              "& .MuiButton-startIcon": { mr: collapsed ? 0 : 1 },
            }}
          >
            {!collapsed && "Déconnexion"}
          </Button>
        </Tooltip>
        {!mobile && (
          <Tooltip
            title={collapsed ? "Déployer le menu" : "Réduire le menu"}
            placement="right"
          >
            <IconButton
              color="inherit"
              onClick={() => setCollapsed((value) => !value)}
              sx={{ width: "100%", borderRadius: 1.5, mt: 0.5 }}
            >
              {collapsed ? (
                <ChevronRightOutlined />
              ) : (
                <>
                  <ChevronLeftOutlined />
                  <Typography variant="caption" sx={{ ml: 1 }}>
                    Réduire
                  </Typography>
                </>
              )}
            </IconButton>
          </Tooltip>
        )}
      </Box>
    </Box>
  );

  return (
    <Box
      sx={{
        display: "flex",
        minHeight: "100vh",
        bgcolor: "#f4f7fb",
        width: "100%",
      }}
    >
      <AppBar
        position="fixed"
        color="inherit"
        elevation={0}
        sx={{
          ml: { md: `${currentDrawerWidth}px` },
          width: { xs: "100%", md: `calc(100% - ${currentDrawerWidth}px)` },
          transition: theme.transitions.create(["margin", "width"]),
        }}
      >
        <Toolbar
          sx={{ borderBottom: "1px solid #e5eaf1", px: { xs: 1.5, sm: 3 } }}
        >
          {mobile && (
            <IconButton
              edge="start"
              onClick={() => setMobileOpen(true)}
              aria-label="Ouvrir le menu"
              sx={{ mr: 1 }}
            >
              <MenuOutlined />
            </IconButton>
          )}
          <Typography
            variant="h6"
            noWrap
            sx={{ flexGrow: 1, fontSize: { xs: "1rem", sm: "1.25rem" } }}
          >
            {titleByPath[location.pathname] ?? "RiskPilot"}
          </Typography>
          <Stack direction="row" spacing={1.5} alignItems="center">
            <Avatar sx={{ bgcolor: "#1769e0", width: 34, height: 34 }}>
              {user.firstName[0]}
              {user.lastName[0]}
            </Avatar>
            <Box sx={{ display: { xs: "none", sm: "block" } }}>
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
        variant={mobile ? "temporary" : "permanent"}
        open={mobile ? mobileOpen : true}
        onClose={() => setMobileOpen(false)}
        ModalProps={{ keepMounted: true }}
        sx={{
          width: mobile ? drawerWidth : currentDrawerWidth,
          flexShrink: 0,
          "& .MuiDrawer-paper": {
            width: mobile ? drawerWidth : currentDrawerWidth,
            bgcolor: "#062b4b",
            color: "white",
            border: 0,
            transition: theme.transitions.create("width"),
          },
        }}
      >
        {drawerContent}
      </Drawer>
      <Box
        component="main"
        sx={{
          flexGrow: 1,
          minWidth: 0,
          pt: { xs: 9, sm: 10 },
          pb: { xs: 3, sm: 5 },
          width: { xs: "100%", md: `calc(100% - ${currentDrawerWidth}px)` },
          transition: theme.transitions.create("width"),
        }}
      >
        <Container maxWidth="xl" sx={{ px: { xs: 1.5, sm: 3 } }}>
          <Outlet />
        </Container>
      </Box>
    </Box>
  );
}

export default function App() {
  return (
    <Suspense
      fallback={
        <Stack minHeight="50vh" alignItems="center" justifyContent="center">
          <CircularProgress aria-label="Chargement de la page" />
        </Stack>
      }
    >
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
            <Route
              path="administration/organizations"
              element={<OrganizationsPage />}
            />
            <Route
              path="administration/audit-logs"
              element={<AuditLogsPage />}
            />
            <Route path="profile" element={<ProfilePage />} />
            <Route
              path="administration/email-settings"
              element={<EmailSettingsPage />}
            />
            <Route path="reports/executive" element={<ExecutiveReportPage />} />
          </Route>
        </Route>
        <Route path="*" element={<Navigate to="/" replace />} />
      </Routes>
    </Suspense>
  );
}
