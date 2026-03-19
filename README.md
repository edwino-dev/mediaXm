# 📂 mediaXm – Gestor de Archivos Multimedia

Sistema ligero en **PHP** para organizar, subir, visualizar y gestionar música, videos e imágenes.  
Diseñado con fines **educativos** y para demostrar la aplicación práctica de **patrones de diseño GoF** en un contexto real.

![PHP](https://img.shields.io/badge/PHP-8.1%2B-777BB4?style=flat&logo=php)
![MySQL](https://img.shields.io/badge/MySQL-4479A1?style=flat&logo=mysql&logoColor=white)
![MIT](https://img.shields.io/badge/license-MIT-green?style=flat)
![Status]https://img.shields.io/badge/status-active-success?style=flat)
```


## 🚀 Características principales
- Subida y almacenamiento seguro de **archivos multimedia** (imágenes, audio, video).  
- Organización por categorías o etiquetas.  
- Visualización en una interfaz web simple.  
- Base de datos **MySQL/MariaDB** para metadatos (nombre, tipo, tamaño, fecha, etc.).  
- Separación de responsabilidades (**config**, **models**, **patterns**, **uploads**).  
- Implementación práctica de **patrones de diseño** clásicos.  

---

## 📁 Estructura del proyecto
```bash
mediaXm/
├── index.php               # Página principal
├── css/
│   └── styles.css          # Estilos completos
├── config/
│   └── database.php        # Configuración (DB, rutas, etc.)
├── models/
│   └── archivo.php         # Entidades y consultas a base de datos
├── patterns/
│   ├── adapter.php
│   ├── decorator.php
│   ├── observer.php
│   └── strategy.php        # Implementaciones de patrones de diseño
├── uploads/
│   ├── images/
│   ├── music/
│   └── video/              # Archivos multimedia subidos
├── mediaManager.php        
├── schema.sql              # Estructura de tablas MySQL
└── README.md
```

---

## 🧩 Patrones de diseño implementados

| Patrón       | Archivo                     | Uso principal                                                                 |
|--------------|-----------------------------|-------------------------------------------------------------------------------|
| **Singleton** | `patterns/Singleton.php`   | Conexión única a la base de datos (evita múltiples conexiones abiertas).      |
| **Factory**   | `patterns/Factory.php`     | Creación de objetos Media (Image, Video, Audio) sin exponer lógica interna.   |
| **Strategy**  | `patterns/Strategy.php`    | Procesamiento/validación según tipo de archivo (ej: compresión de imagen).    |
| **Observer**  | `patterns/Observer.php`    | Notificación al subir un archivo (ej: generar thumbnail, loggear eventos).    |

---

## ⚙️ Instalación

1. **Clona el repositorio**
   ```bash
   git clone https://github.com/edwino-dev/mediaXm.git
   cd mediaXm
   ```

2. **Crea la base de datos y ejecuta el esquema**
   ```bash
   mysql -u root -p < schema.sql
   ```

3. **Configura la conexión en `config/database.php`**
   ```php
   return [
       'host'     => 'localhost',
       'dbname'   => 'media_xm',
       'user'     => 'root',
       'password' => '',
       'charset'  => 'utf8mb4',
   ];
   ```

4. **Permisos de escritura en la carpeta `uploads/`**
   ```bash
   chmod -R 775 uploads/
   ```
   *(En Windows, ajusta manualmente desde el explorador o propiedades de carpeta).*

5. **Accede desde el navegador**
   ```
   http://localhost/mediaXm/index.php
   ```

---

## 📖 Uso básico
- Accede a `index.php`.  
- Sube archivos desde el formulario.  
- Visualiza la lista de medios subidos.  

---

## 🛠️ Tecnologías utilizadas
- **PHP 8.1+**  
- **MySQL / MariaDB**  
- **HTML + CSS**  
- **Patrones de diseño GoF**  

---

## 📜 Licencia
Este proyecto está bajo la licencia **MIT**. Puedes usarlo, modificarlo y distribuirlo libremente.

---

