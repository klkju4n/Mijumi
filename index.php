<?php
session_start();

$error = "";
$success = isset($_GET["saved"]) ? "Los cambios se guardaron correctamente." : "";

try {
    require __DIR__ . "/conexion.php";

    $initialMembers = [
        "Juan Alberto Virgen Lopez",
        "Milena Adylen Hernandez Torres",
    ];

    $db->query(
        "CREATE TABLE IF NOT EXISTS integrantes (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            nombre VARCHAR(120) NOT NULL,
            orden INT NOT NULL DEFAULT 0
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $columns = $db->query("SHOW COLUMNS FROM integrantes")->fetch_all(MYSQLI_ASSOC);
    $hasOrderColumn = false;
    foreach ($columns as $column) {
        if ($column["Field"] === "orden") {
            $hasOrderColumn = true;
        }
        if ($column["Field"] === "nombre" && strtolower($column["Type"]) !== "varchar(120)") {
            $db->query("ALTER TABLE integrantes MODIFY nombre VARCHAR(120) NOT NULL");
        }
    }

    if (!$hasOrderColumn) {
        $db->query("ALTER TABLE integrantes ADD COLUMN orden INT NOT NULL DEFAULT 0");
        $db->query("UPDATE integrantes SET orden = id + 2");

        $findMember = $db->prepare("SELECT id FROM integrantes WHERE nombre = ? LIMIT 1");
        $updateOrder = $db->prepare("UPDATE integrantes SET orden = ? WHERE id = ?");
        $insertMember = $db->prepare("INSERT INTO integrantes (nombre, orden) VALUES (?, ?)");
        foreach ($initialMembers as $order => $name) {
            $findMember->bind_param("s", $name);
            $findMember->execute();
            $existingMember = $findMember->get_result()->fetch_assoc();
            if ($existingMember) {
                $memberId = (int) $existingMember["id"];
                $updateOrder->bind_param("ii", $order, $memberId);
                $updateOrder->execute();
            } else {
                $insertMember->bind_param("si", $name, $order);
                $insertMember->execute();
            }
        }
        $findMember->close();
        $updateOrder->close();
        $insertMember->close();
    }

    $countResult = $db->query("SELECT COUNT(*) AS total FROM integrantes");
    $count = (int) $countResult->fetch_assoc()["total"];
    if ($count === 0) {
        $insert = $db->prepare("INSERT INTO integrantes (nombre, orden) VALUES (?, ?)");
        foreach ($initialMembers as $order => $name) {
            $insert->bind_param("si", $name, $order);
            $insert->execute();
        }
        $insert->close();
    }

    if (empty($_SESSION["csrf_token"])) {
        $_SESSION["csrf_token"] = bin2hex(random_bytes(32));
    }

    if ($_SERVER["REQUEST_METHOD"] === "POST") {
        $submittedToken = $_POST["csrf_token"] ?? "";
        if (!is_string($submittedToken) || !hash_equals($_SESSION["csrf_token"], $submittedToken)) {
            throw new RuntimeException("La sesión del formulario venció. Recarga la página e inténtalo de nuevo.");
        }

        $action = $_POST["action"] ?? "";
        if ($action === "save") {
            $names = $_POST["names"] ?? null;
            if (!is_array($names) || count($names) === 0) {
                throw new RuntimeException("No se recibieron nombres para guardar.");
            }

            $db->begin_transaction();
            try {
                $update = $db->prepare("UPDATE integrantes SET nombre = ? WHERE id = ?");
                foreach ($names as $id => $name) {
                    if (!ctype_digit((string) $id) || !is_string($name)) {
                        throw new RuntimeException("Los datos recibidos no son válidos.");
                    }
                    $name = trim($name);
                    if (!preg_match("/^.{1,120}$/us", $name)) {
                        throw new RuntimeException("Cada nombre debe tener entre 1 y 120 caracteres.");
                    }
                    $memberId = (int) $id;
                    $update->bind_param("si", $name, $memberId);
                    $update->execute();
                    if ($update->affected_rows === 0 && $update->warning_count > 0) {
                        throw new RuntimeException("No se pudo guardar uno de los nombres.");
                    }
                }
                $update->close();
                $db->commit();
            } catch (Throwable $exception) {
                $db->rollback();
                throw $exception;
            }
        } elseif ($action === "add") {
            $name = $_POST["new_name"] ?? "";
            if (!is_string($name)) {
                throw new RuntimeException("El nombre recibido no es válido.");
            }
            $name = trim($name);
            if (!preg_match("/^.{1,120}$/us", $name)) {
                throw new RuntimeException("El nombre debe tener entre 1 y 120 caracteres.");
            }

            $orderResult = $db->query("SELECT COALESCE(MAX(orden), -1) + 1 AS next_order FROM integrantes");
            $order = (int) $orderResult->fetch_assoc()["next_order"];
            $insert = $db->prepare("INSERT INTO integrantes (nombre, orden) VALUES (?, ?)");
            $insert->bind_param("si", $name, $order);
            $insert->execute();
            $insert->close();
        } else {
            throw new RuntimeException("La acción solicitada no es válida.");
        }

        header("Location: index.php?saved=1");
        exit;
    }

    $members = $db->query("SELECT id, nombre FROM integrantes ORDER BY orden, id")->fetch_all(MYSQLI_ASSOC);
} catch (Throwable $exception) {
    $error = $exception instanceof mysqli_sql_exception
        ? "No se pudo conectar o consultar MySQL. Comprueba que XAMPP esté activo, que MySQL use el puerto 3308 y que exista la base de datos MyTeam."
        : $exception->getMessage();
    $members = [];
}

function escapeHtml(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8");
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Mi equipo mijumi</title>
    <style>
        body {
            margin: 0;
            padding: 32px 16px;
            text-align: center;
            font-family: Arial, sans-serif;
            background-color: #0a3161;
            color: white;
        }
        main {
            width: min(100%, 560px);
            margin: 0 auto;
        }
        .member-list {
            padding: 0;
            list-style: none;
        }
        .member {
            display: flex;
            gap: 10px;
            margin: 12px 0;
        }
        input, button {
            box-sizing: border-box;
            padding: 11px 12px;
            border: 0;
            border-radius: 5px;
            font: inherit;
        }
        .member input {
            flex: 1;
            min-width: 0;
        }
        button {
            color: #0a3161;
            background: white;
            cursor: pointer;
            font-weight: bold;
        }
        button:hover {
            background: #e5eef8;
        }
        .add-form {
            display: flex;
            gap: 10px;
            margin-top: 24px;
        }
        .add-form input {
            flex: 1;
            min-width: 0;
        }
        .message {
            padding: 12px;
            border-radius: 5px;
            background: #e5eef8;
            color: #123;
        }
        .error {
            background: #ffe4e4;
            color: #8a1111;
        }
        @media (max-width: 420px) {
            .member, .add-form {
                flex-direction: column;
            }
        }
    </style>
</head>
<body>
<main>
    <h1>Mi equipo mijumi</h1>
    <h2>Integrantes</h2>

    <?php if ($success !== ""): ?>
        <p class="message"><?= escapeHtml($success) ?></p>
    <?php endif; ?>
    <?php if ($error !== ""): ?>
        <p class="message error"><?= escapeHtml($error) ?></p>
    <?php endif; ?>

    <?php if ($error === ""): ?>
        <form method="post">
            <input type="hidden" name="csrf_token" value="<?= escapeHtml($_SESSION["csrf_token"]) ?>">
            <input type="hidden" name="action" value="save">
            <ul class="member-list">
                <?php foreach ($members as $member): ?>
                    <li class="member">
                        <input
                            type="text"
                            name="names[<?= (int) $member["id"] ?>]"
                            value="<?= escapeHtml($member["nombre"]) ?>"
                            maxlength="120"
                            aria-label="Nombre del integrante"
                            required
                        >
                    </li>
                <?php endforeach; ?>
            </ul>
            <button type="submit">Guardar nombres</button>
        </form>

        <form method="post" class="add-form">
            <input type="hidden" name="csrf_token" value="<?= escapeHtml($_SESSION["csrf_token"]) ?>">
            <input type="hidden" name="action" value="add">
            <input type="text" name="new_name" maxlength="120" placeholder="Nombre del nuevo integrante" required>
            <button type="submit">Agregar integrante</button>
        </form>
    <?php endif; ?>
</main>
</body>
</html>
