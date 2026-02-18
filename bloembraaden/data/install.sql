-- PAY ATTENTION each table must have a primary key column, which is returned upon insert
-- automatically, Bloembraaden crashes if the primary key is missing

BEGIN;

-- CREATE TABLE "_user" ----------------------------------------
DROP TABLE IF EXISTS "public"."_user";
CREATE TABLE "public"."_user"
(
    "user_id"       Serial PRIMARY KEY,
    "instance_id"   int                      DEFAULT (0)   NOT NULL,
    "email"         Character Varying(255)                 NOT NULL,
    "nickname"      Character Varying(255)                 NOT NULL,
    "password_hash" Character Varying(255)                 NOT NULL,
    "date_created"  Timestamp With Time Zone DEFAULT NOW() NOT NULL,
    "date_updated"  Timestamp With Time Zone DEFAULT NOW() NOT NULL,
    "deleted"       Boolean                  DEFAULT false NOT NULL,
    CONSTRAINT "unique__user_user_id" UNIQUE ("user_id"),
    CONSTRAINT "unique__user_nickname" UNIQUE ("instance_id", "nickname"),
    CONSTRAINT "unique__user_email" UNIQUE ("instance_id", "email")
);
;
-- -------------------------------------------------------------

-- CREATE TABLE "_admin" ---------------------------------------
DROP TABLE IF EXISTS "public"."_admin";
CREATE TABLE "public"."_admin"
(
    "admin_id"      Serial PRIMARY KEY,
    "client_id"     int                      DEFAULT (0)   NOT NULL,
    "email"         Character Varying(255)                 NOT NULL,
    "nickname"      Character Varying(255)                 NOT NULL,
    "password_hash" Character Varying(255)                 NOT NULL,
    "date_created"  Timestamp With Time Zone DEFAULT NOW() NOT NULL,
    "date_updated"  Timestamp With Time Zone DEFAULT NOW() NOT NULL,
    "deleted"       Boolean                  DEFAULT false NOT NULL,
    CONSTRAINT "unique__admin_admin_id" UNIQUE ("admin_id"),
    CONSTRAINT "unique__admin_nickname" UNIQUE ("nickname"),
    CONSTRAINT "unique__admin_e-mail" UNIQUE ("email")
);
;
-- -------------------------------------------------------------

-- CREATE TABLE "_session" -------------------------------------
DROP TABLE IF EXISTS "public"."_session";
CREATE TABLE "public"."_session"
(
    "token"         CHAR(32) PRIMARY KEY,
    "session_id"    Bigserial                              NOT NULL,
    "admin_id"      int                      DEFAULT (0)   NOT NULL,
    "user_id"       int                      DEFAULT (0)   NOT NULL,
    "date_created"  Timestamp With Time Zone DEFAULT NOW() NOT NULL,
    "date_accessed" Timestamp With Time Zone DEFAULT NOW() NOT NULL,
    "deleted"       Boolean                  DEFAULT false NOT NULL,
    CONSTRAINT "unique__session_session_id" UNIQUE ("session_id"),
    CONSTRAINT "unique__session_token" UNIQUE ("token")
);
;
-- -------------------------------------------------------------


-- CREATE TABLE "_instance" ------------------------------------
DROP TABLE IF EXISTS "public"."_instance";
CREATE TABLE "public"."_instance"
(
    "instance_id"  Serial PRIMARY KEY,
    "client_id"    Integer                                NOT NULL,
    "name"         Character Varying(40)                  NOT NULL,
    "domain"       Character Varying(255)                 NOT NULL,
    "date_created" Timestamp With Time Zone DEFAULT NOW() NOT NULL,
    "date_updated" Timestamp With Time Zone DEFAULT NOW() NOT NULL,
    "deleted"      Boolean                  DEFAULT false NOT NULL,
    CONSTRAINT "unique__instance_instance_id" UNIQUE ("instance_id"),
    CONSTRAINT "unique__instance_domain" UNIQUE ("domain")
);
;
-- -------------------------------------------------------------

-- CREATE TABLE "_client" --------------------------------------
DROP TABLE IF EXISTS "public"."_client";
CREATE TABLE "public"."_client"
(
    "client_id"    Serial PRIMARY KEY,
    "name"         Character Varying(40)                  NOT NULL,
    "date_created" Timestamp With Time Zone DEFAULT NOW() NOT NULL,
    "date_updated" Timestamp With Time Zone DEFAULT NOW() NOT NULL,
    "deleted"      Boolean                  DEFAULT false NOT NULL,
    CONSTRAINT "unique__client_client_id" UNIQUE ("client_id")
);
;
-- -------------------------------------------------------------

-- CREATE TABLE "_instance_domain" -----------------------------
DROP TABLE IF EXISTS "public"."_instance_domain";
CREATE TABLE "public"."_instance_domain"
(
    "instance_domain_id" Serial PRIMARY KEY,
    "instance_id"        Integer                                NOT NULL,
    "domain"             Character Varying(255)                 NOT NULL,
    "date_created"       Timestamp With Time Zone DEFAULT NOW() NOT NULL,
    "date_updated"       Timestamp With Time Zone DEFAULT NOW() NOT NULL,
    "deleted"            Boolean                  DEFAULT false NOT NULL,
    CONSTRAINT "unique__instance_domain_instance_domain_id" UNIQUE ("instance_domain_id"),
    CONSTRAINT "unique__instance_domain_domain" UNIQUE ("domain")
);
;
-- -------------------------------------------------------------

COMMIT;

-- version 0.1.0

BEGIN;

-- CREATE FIELD "instance_id" -----------------------------------
ALTER TABLE "public"."_session"
    ADD COLUMN "instance_id" Integer DEFAULT 0 NOT NULL;
-- -------------------------------------------------------------

-- CREATE FIELD "dateupdated" ----------------------------------
ALTER TABLE "public"."_session"
    ADD COLUMN "date_updated" Timestamp With Time Zone DEFAULT NOW() NOT NULL;
-- -------------------------------------------------------------

-- CREATE TABLE "_sessionvars" ---------------------------------
DROP TABLE IF EXISTS "public"."_sessionvars";
CREATE TABLE "public"."_sessionvars"
(
    "sessionvars_id" Serial PRIMARY KEY,
    "session_id"     Bigint                  NOT NULL,
    "name"           Character Varying(40)   NOT NULL,
    "value"          Character Varying(2044) NOT NULL,
    CONSTRAINT "unique__sessionvars_sessionvars_id" UNIQUE ("sessionvars_id")
);
;
-- -------------------------------------------------------------

-- CREATE FIELDS for presentation templates --------------------
ALTER TABLE "public"."_instance"
    ADD COLUMN "presentation_admin" Character Varying(40) DEFAULT 'peatcms' NOT NULL;
ALTER TABLE "public"."_instance"
    ADD COLUMN "presentation_theme" Character Varying(40) DEFAULT 'peatcms' NOT NULL;
ALTER TABLE "public"."_instance"
    ADD COLUMN "presentation_instance" Character Varying(40) DEFAULT 'peatcms' NOT NULL;
-- -------------------------------------------------------------


COMMIT;

-- version 0.2.0

BEGIN;

-- CREATE TABLE "cms_page" -------------------------------------
DROP TABLE IF EXISTS "public"."cms_page";
CREATE TABLE "public"."cms_page"
(
    "page_id"        Serial PRIMARY KEY,
    "instance_id"    Integer                                NOT NULL,
    "title"          Character Varying(127)                 NOT NULL,
    "slug"           Character Varying(127)                 NOT NULL,
    "date_published" Timestamp With Time Zone,
    "content"        Text                                   NOT NULL,
    "template"       Character Varying(40)                  NOT NULL,
    "date_created"   Timestamp With Time Zone DEFAULT NOW() NOT NULL,
    "date_updated"   Timestamp With Time Zone DEFAULT NOW() NOT NULL,
    "online"         Boolean                  DEFAULT false NOT NULL,
    "deleted"        Boolean                  DEFAULT false NOT NULL,
    CONSTRAINT "unique_cms_page_page_id" UNIQUE ("page_id")
);
;
-- -------------------------------------------------------------

-- CREATE TABLE "cms_file" -------------------------------------
DROP TABLE IF EXISTS "public"."cms_file";
CREATE TABLE "public"."cms_file"
(
    "file_id"        Serial PRIMARY KEY,
    "instance_id"    Integer                                NOT NULL,
    "title"          Character Varying(127)                 NOT NULL,
    "slug"           Character Varying(127)                 NOT NULL,
    "filename_saved" Character Varying(127)                 NOT NULL,
    "content_type"   Character Varying(40)                  NOT NULL,
    "protected"      Boolean                  DEFAULT false NOT NULL,
    "date_created"   Timestamp With Time Zone DEFAULT NOW() NOT NULL,
    "date_updated"   Timestamp With Time Zone DEFAULT NOW() NOT NULL,
    "online"         Boolean                  DEFAULT false NOT NULL,
    "deleted"        Boolean                  DEFAULT false NOT NULL,
    CONSTRAINT "unique_cms_file_file_id" UNIQUE ("file_id")
);
;
-- -------------------------------------------------------------

-- CREATE TABLE "cms_file_x_page" ------------------------------
DROP TABLE IF EXISTS "public"."cms_file_x_page";
CREATE TABLE "public"."cms_file_x_page"
(
    "file_x_page_id" Serial PRIMARY KEY,
    "sub_file_id"    Integer                                NOT NULL,
    "page_id"        Integer                                NOT NULL,
    "o"              Integer                  DEFAULT 1     NOT NULL,
    "date_created"   Timestamp With Time Zone DEFAULT NOW() NOT NULL,
    "date_updated"   Timestamp With Time Zone DEFAULT NOW() NOT NULL,
    "online"         Boolean                  DEFAULT false NOT NULL,
    "deleted"        Boolean                  DEFAULT false NOT NULL,
    CONSTRAINT "unique_cms_file_x_page_file_x_page_id" UNIQUE ("file_x_page_id")
);
;
-- -------------------------------------------------------------

-- CREATE TABLE "cms_subpage_x_page" ---------------------------
DROP TABLE IF EXISTS "public"."cms_page_x_page";
CREATE TABLE "public"."cms_page_x_page"
(
    "page_x_page_id" Serial PRIMARY KEY,
    "sub_page_id"    Integer                                NOT NULL,
    "page_id"        Integer                                NOT NULL,
    "o"              Integer                  DEFAULT 1     NOT NULL,
    "date_created"   Timestamp With Time Zone DEFAULT NOW() NOT NULL,
    "date_updated"   Timestamp With Time Zone DEFAULT NOW() NOT NULL,
    "online"         Boolean                  DEFAULT false NOT NULL,
    "deleted"        Boolean                  DEFAULT false NOT NULL,
    CONSTRAINT "unique_cms_page_x_page_page_x_page_id" UNIQUE ("page_x_page_id")
);
;
-- -------------------------------------------------------------

-- CREATE FIELD "is_account" -----------------------------------
ALTER TABLE "public"."_user"
    ADD COLUMN "is_account" BOOLEAN DEFAULT false NOT NULL;
-- -------------------------------------------------------------

-- CREATE FIELD "homepage_id" ----------------------------------
ALTER TABLE "public"."_instance"
    ADD COLUMN "homepage_id" Integer DEFAULT 0 NOT NULL;
-- -------------------------------------------------------------

-- CREATE FIELD "filename_original" ----------------------------
ALTER TABLE "public"."cms_file"
    ADD COLUMN "filename_original" Character Varying(127);
-- -------------------------------------------------------------

-- sub order in link tables

-- CHANGE "TYPE" OF "FIELD "o" ---------------------------------
ALTER TABLE "public"."cms_page_x_page"
    ALTER COLUMN "o" TYPE SmallInt;
-- -------------------------------------------------------------

-- CREATE FIELD "sub_o" ----------------------------------------
ALTER TABLE "public"."cms_page_x_page"
    ADD COLUMN "sub_o" SmallInt DEFAULT 1 NOT NULL;
-- -------------------------------------------------------------

-- CHANGE "TYPE" OF "FIELD "o" ---------------------------------
ALTER TABLE "public"."cms_file_x_page"
    ALTER COLUMN "o" TYPE SmallInt;
-- -------------------------------------------------------------

-- CREATE FIELD "sub_o" ----------------------------------------
ALTER TABLE "public"."cms_file_x_page"
    ADD COLUMN "sub_o" SmallInt DEFAULT 1 NOT NULL;
-- -------------------------------------------------------------


COMMIT;

-- version 0.3.0

BEGIN;

-- images

-- CREATE TABLE "cms_image" ------------------------------------
DROP TABLE IF EXISTS "public"."cms_image";
CREATE TABLE "public"."cms_image"
(
    "image_id"          Serial PRIMARY KEY,
    "instance_id"       Integer                                NOT NULL,
    "title"             Character Varying(127)                 NOT NULL,
    "slug"              Character Varying(127)                 NOT NULL,
    "filename_saved"    Character Varying(127)                 NOT NULL,
    "filename_original" Character Varying(127)                 NOT NULL,
    "content_type"      Character Varying(40)                  NOT NULL,
    "date_created"      Timestamp With Time Zone DEFAULT NOW() NOT NULL,
    "date_updated"      Timestamp With Time Zone DEFAULT NOW() NOT NULL,
    "online"            Boolean                  DEFAULT false NOT NULL,
    "deleted"           Boolean                  DEFAULT false NOT NULL,
    CONSTRAINT "unique_cms_image_image_id" UNIQUE ("image_id")
);
;
-- CREATE TABLE "cms_image_x_page" -----------------------------
DROP TABLE IF EXISTS "public"."cms_image_x_page";
CREATE TABLE "public"."cms_image_x_page"
(
    "image_x_page_id" Serial PRIMARY KEY,
    "sub_image_id"    Integer                                NOT NULL,
    "page_id"         Integer                                NOT NULL,
    "o"               SmallInt                 DEFAULT 1     NOT NULL,
    "sub_o"           SmallInt                 DEFAULT 1     NOT NULL,
    "date_created"    Timestamp With Time Zone DEFAULT NOW() NOT NULL,
    "date_updated"    Timestamp With Time Zone DEFAULT NOW() NOT NULL,
    "online"          Boolean                  DEFAULT false NOT NULL,
    "deleted"         Boolean                  DEFAULT false NOT NULL,
    CONSTRAINT "unique_cms_image_x_page_image_x_page_id" UNIQUE ("image_x_page_id")
);
;

-- CREATE FIELD "extension" ------------------------------------
ALTER TABLE "public"."cms_image"
    ADD COLUMN "extension" Character Varying(20) DEFAULT '' NOT NULL;
-- -------------------------------------------------------------


COMMIT;

-- version 0.4.0

BEGIN;

-- extend image element

-- CREATE FIELD "src_small" ------------------------------------
ALTER TABLE "public"."cms_image"
    ADD COLUMN "src_small" Character Varying(255);
-- -------------------------------------------------------------

-- CREATE FIELD "width_small" ----------------------------------
ALTER TABLE "public"."cms_image"
    ADD COLUMN "width_small" SmallInt;
-- -------------------------------------------------------------

-- CREATE FIELD "height_small" ---------------------------------
ALTER TABLE "public"."cms_image"
    ADD COLUMN "height_small" SmallInt;
-- -------------------------------------------------------------

-- CREATE FIELD "src_medium" -----------------------------------
ALTER TABLE "public"."cms_image"
    ADD COLUMN "src_medium" Character Varying(255);
-- -------------------------------------------------------------

-- CREATE FIELD "width_medium" ---------------------------------
ALTER TABLE "public"."cms_image"
    ADD COLUMN "width_medium" SmallInt;
-- -------------------------------------------------------------

-- CREATE FIELD "height_medium" --------------------------------
ALTER TABLE "public"."cms_image"
    ADD COLUMN "height_medium" SmallInt;
-- -------------------------------------------------------------

-- CREATE FIELD "src_large" ------------------------------------
ALTER TABLE "public"."cms_image"
    ADD COLUMN "src_large" Character Varying(255);
-- -------------------------------------------------------------

-- CREATE FIELD "width_large" ----------------------------------
ALTER TABLE "public"."cms_image"
    ADD COLUMN "width_large" SmallInt;
-- -------------------------------------------------------------

-- CREATE FIELD "height_large" ---------------------------------
ALTER TABLE "public"."cms_image"
    ADD COLUMN "height_large" SmallInt;
-- -------------------------------------------------------------

-- CREATE TABLE "_system" --------------------------------------
DROP TABLE IF EXISTS "public"."_system";
CREATE TABLE "public"."_system"
(
    "version" Character Varying(40) NOT NULL,
    CONSTRAINT "unique__system_version" UNIQUE ("version")
);
;
-- -------------------------------------------------------------


COMMIT;

-- version 0.4.1

BEGIN;

ALTER TABLE _instance_domain
    DROP CONSTRAINT IF EXISTS "unique__instance_domain_domain";
ALTER TABLE _instance_domain
    ADD CONSTRAINT "unique__instance_domain_domain" UNIQUE ("domain", "instance_id");

-- CREATE FIELD "excerpt" for page -----------------------------
ALTER TABLE "public"."cms_page"
    ADD COLUMN "excerpt" Text;
-- -------------------------------------------------------------

-- CREATE FIELD "description" for image ------------------------
ALTER TABLE "public"."cms_image"
    ADD COLUMN "description" Text;
-- -------------------------------------------------------------


COMMIT;

-- version 0.4.2

BEGIN;

ALTER TABLE _instance_domain
    DROP CONSTRAINT IF EXISTS "unique__instance_domain_domain";
ALTER TABLE _instance
    DROP CONSTRAINT IF EXISTS "unique__instance_domain";

COMMIT;

-- version 0.4.3

BEGIN;

-- CREATE FIELD "extension" for file ---------------------------
ALTER TABLE "public"."cms_file"
    ADD COLUMN "extension" Character Varying(12);
-- -------------------------------------------------------------

COMMIT;

-- version 0.4.4

BEGIN;

-- CREATE INFO FIELDS for session -----------------------------
ALTER TABLE "public"."_session"
    ADD COLUMN "ip_address" Character Varying(45);
ALTER TABLE "public"."_session"
    ADD COLUMN "reverse_dns" Character Varying(255);
ALTER TABLE "public"."_session"
    ADD COLUMN "user_agent" Character Varying(255);
-- -------------------------------------------------------------

-- CREATE FIELD "css_class" for elements -----------------------
ALTER TABLE "public"."cms_page"
    ADD COLUMN "css_class" Character varying(255) DEFAULT '' NOT NULL;
ALTER TABLE "public"."cms_file"
    ADD COLUMN "css_class" Character varying(255) DEFAULT '' NOT NULL;
ALTER TABLE "public"."cms_image"
    ADD COLUMN "css_class" Character varying(255) DEFAULT '' NOT NULL;
-- -------------------------------------------------------------

-- CREATE FIELD "instance_id" for admin ------------------------
ALTER TABLE "public"."_admin"
    ADD COLUMN "instance_id" INTEGER DEFAULT 0 NOT NULL;
-- -------------------------------------------------------------

COMMIT;

-- version 0.4.5

BEGIN;
-- add google_tracking_id to instance
ALTER TABLE "public"."_instance"
    ADD COLUMN "google_tracking_id" Character Varying(1024);

-- add video element
-- CREATE TABLE "cms_video" ------------------------------------
DROP TABLE IF EXISTS "public"."cms_video";
CREATE TABLE "public"."cms_video"
(
    "video_id"     Serial PRIMARY KEY,
    "instance_id"  Integer                                NOT NULL,
    "title"        Character Varying(127)                 NOT NULL,
    "slug"         Character Varying(127)                 NOT NULL,
    "css_class"    Character varying(255)    DEFAULT ''    NOT NULL,
    "date_created" Timestamp With Time Zone DEFAULT NOW() NOT NULL,
    "date_updated" Timestamp With Time Zone DEFAULT NOW() NOT NULL,
    "online"       Boolean                  DEFAULT false NOT NULL,
    "deleted"      Boolean                  DEFAULT false NOT NULL,
    CONSTRAINT "unique_cms_video_video_id" UNIQUE ("video_id")
);
;

-- CREATE TABLE "cms_video_file" -------------------------------
DROP TABLE IF EXISTS "public"."cms_video_file";
CREATE TABLE "public"."cms_video_file"
(
    "video_file_id"     Serial PRIMARY KEY,
    "video_id"          INTEGER                                NOT NULL,
    "filename_saved"    Character Varying(127)                 NOT NULL,
    "filename_original" Character Varying(127)                 NOT NULL,
    "content_type"      Character Varying(40)                  NOT NULL,
    "extension"         Character Varying(12),
    "date_created"      Timestamp With Time Zone DEFAULT NOW() NOT NULL,
    "date_updated"      Timestamp With Time Zone DEFAULT NOW() NOT NULL,
    "online"            Boolean                  DEFAULT false NOT NULL,
    "deleted"           Boolean                  DEFAULT false NOT NULL,
    CONSTRAINT "unique_cms_video_file_video_file_id" UNIQUE ("video_file_id")
);
;

-- CREATE TABLE "cms_video_x_page" -----------------------------
DROP TABLE IF EXISTS "public"."cms_video_x_page";
CREATE TABLE "public"."cms_video_x_page"
(
    "video_x_page_id" Serial PRIMARY KEY,
    "sub_video_id"    Integer                                NOT NULL,
    "page_id"         Integer                                NOT NULL,
    "o"               SmallInt                 DEFAULT 1     NOT NULL,
    "sub_o"           SmallInt                 DEFAULT 1     NOT NULL,
    "date_created"    Timestamp With Time Zone DEFAULT NOW() NOT NULL,
    "date_updated"    Timestamp With Time Zone DEFAULT NOW() NOT NULL,
    "online"          Boolean                  DEFAULT false NOT NULL,
    "deleted"         Boolean                  DEFAULT false NOT NULL,
    CONSTRAINT "unique_cms_video_x_page_video_x_page_id" UNIQUE ("video_x_page_id")
);
;

COMMIT;

-- version 0.4.6

BEGIN;

-- add the menu and menu_item with its cross table to make submenus (linking menu_items to each other)

-- CREATE TABLE "cms_menu" ---------------------------------------
DROP TABLE IF EXISTS "public"."cms_menu";
CREATE TABLE "public"."cms_menu"
(
    "menu_id"      Serial PRIMARY KEY,
    "instance_id"  Integer                                NOT NULL,
    "title"        Character Varying(127)                 NOT NULL,
    "slug"         Character Varying(127)                 NOT NULL,
    "date_created" Timestamp With Time Zone DEFAULT NOW() NOT NULL,
    "date_updated" Timestamp With Time Zone DEFAULT NOW() NOT NULL,
    "online"       Boolean                  DEFAULT false NOT NULL,
    "deleted"      Boolean                  DEFAULT false NOT NULL,
    CONSTRAINT "unique_cms_menu_menu_id" UNIQUE ("menu_id")
);
;
-- -------------------------------------------------------------

-- CREATE TABLE "cms_menu_item" --------------------------------
DROP TABLE IF EXISTS "public"."cms_menu_item";
CREATE TABLE "public"."cms_menu_item"
(
    "menu_item_id" Serial PRIMARY KEY,
    "title"        Character Varying(127)                 NOT NULL,
    "css_class"    Character varying(255)    DEFAULT ''    NOT NULL,
    "act"          Character Varying(2044)                NOT NULL,
    "content"      Character Varying(2044)                NOT NULL,
    "date_created" Timestamp With Time Zone DEFAULT NOW() NOT NULL,
    "date_updated" Timestamp With Time Zone DEFAULT NOW() NOT NULL,
    "online"       Boolean                  DEFAULT false NOT NULL,
    "deleted"      Boolean                  DEFAULT false NOT NULL,
    CONSTRAINT "unique_cms_menu_item_menu_item_id" UNIQUE ("menu_item_id")
);
;
-- -------------------------------------------------------------

-- CREATE TABLE "cms_menu_item_x_menu_item" --------------------
DROP TABLE IF EXISTS "public"."cms_menu_item_x_menu_item";
CREATE TABLE "public"."cms_menu_item_x_menu_item"
(
    "menu_item_x_menu_item_id" Serial PRIMARY KEY,
    "sub_menu_item_id"         Integer                                NOT NULL,
    "menu_item_id"             Integer                                NOT NULL,
    "o"                        SmallInt                 DEFAULT 1     NOT NULL,
    "sub_o"                    SmallInt                 DEFAULT 1     NOT NULL,
    "date_created"             Timestamp With Time Zone DEFAULT now() NOT NULL,
    "date_updated"             Timestamp With Time Zone DEFAULT now() NOT NULL,
    "online"                   Boolean                  DEFAULT false NOT NULL,
    "deleted"                  Boolean                  DEFAULT false NOT NULL,
    CONSTRAINT "unique_cms_menu_item_x_menu_item_menu_item_x_menu_item_id" UNIQUE ("menu_item_x_menu_item_id")
);
;
-- -------------------------------------------------------------

-- also link the items to the menu
-- CREATE TABLE "cms_menu_item_x_menu" -------------------------
DROP TABLE IF EXISTS "public"."cms_menu_item_x_menu";
CREATE TABLE "public"."cms_menu_item_x_menu"
(
    "menu_item_x_menu_id" Serial PRIMARY KEY,
    "sub_menu_item_id"    Integer                                NOT NULL,
    "menu_id"             Integer                                NOT NULL,
    "o"                   SmallInt                 DEFAULT 1     NOT NULL,
    "sub_o"               SmallInt                 DEFAULT 1     NOT NULL,
    "date_created"        Timestamp With Time Zone DEFAULT NOW() NOT NULL,
    "date_updated"        Timestamp With Time Zone DEFAULT NOW() NOT NULL,
    "online"              Boolean                  DEFAULT false NOT NULL,
    "deleted"             Boolean                  DEFAULT false NOT NULL,
    CONSTRAINT "unique_cms_menu_item_x_menu_menu_item_x_menu_id" UNIQUE ("menu_item_x_menu_id")
);
;

COMMIT;

-- version 0.4.7

BEGIN;

-- CREATE FIELD "instance_id" for menu item --------------------
ALTER TABLE "public"."cms_menu_item"
    ADD COLUMN "instance_id" INTEGER DEFAULT 0 NOT NULL;
-- -------------------------------------------------------------

COMMIT;

-- version 0.4.8

BEGIN;

-- CREATE FIELD "menu_id" for menu item cross table ------------
ALTER TABLE "public"."cms_menu_item_x_menu_item"
    ADD COLUMN "menu_id" INTEGER DEFAULT 0 NOT NULL;
-- -------------------------------------------------------------

COMMIT;

-- version 0.4.10

BEGIN;

-- CREATE FIELD "excerpt" for image ----------------------------
ALTER TABLE "public"."cms_image"
    ADD COLUMN if not exists "excerpt" Text;
-- -------------------------------------------------------------

-- CREATE FIELD "description" for file -------------------------
ALTER TABLE "public"."cms_file"
    ADD COLUMN if not exists "description" Text;
-- -------------------------------------------------------------

-- CREATE FIELD "excerpt" for file -----------------------------
ALTER TABLE "public"."cms_file"
    ADD COLUMN if not exists "excerpt" Text;
-- -------------------------------------------------------------

-- CREATE FIELD "image_id" for file ----------------------------
ALTER TABLE "public"."cms_file"
    ADD COLUMN if not exists "image_id" INTEGER DEFAULT 0 NOT NULL;
-- -------------------------------------------------------------

-- CREATE FIELD "image_id" for page ----------------------------
ALTER TABLE "public"."cms_page"
    ADD COLUMN if not exists "image_id" INTEGER DEFAULT 0 NOT NULL;
-- -------------------------------------------------------------

-- remove video_file, we're not going down that road
DROP TABLE IF EXISTS "public"."cms_video_file";
-- add appropriate columns to video, so people can post an embedded video
ALTER TABLE "public"."cms_video"
    ADD COLUMN if not exists "embed" Text;
ALTER TABLE "public"."cms_video"
    ADD COLUMN if not exists "image_id" INTEGER DEFAULT 0 NOT NULL;
ALTER TABLE "public"."cms_video"
    ADD COLUMN if not exists "description" Text;
ALTER TABLE "public"."cms_video"
    ADD COLUMN if not exists "excerpt" Text;
-- -------------------------------------------------------------

COMMIT;

-- version 0.4.11

BEGIN;
-- remove featured images, bad idea
ALTER TABLE "public"."cms_video"
    DROP COLUMN if exists "image_id";
ALTER TABLE "public"."cms_page"
    DROP COLUMN if exists "image_id";
ALTER TABLE "public"."cms_file"
    DROP COLUMN if exists "image_id";
-- link images to file type
-- CREATE TABLE "cms_image_x_file" -----------------------------
DROP TABLE IF EXISTS "public"."cms_image_x_file";
CREATE TABLE "public"."cms_image_x_file"
(
    "image_x_file_id" Serial PRIMARY KEY,
    "sub_image_id"    Integer                                NOT NULL,
    "file_id"         Integer                                NOT NULL,
    "o"               SmallInt                 DEFAULT 1     NOT NULL,
    "sub_o"           SmallInt                 DEFAULT 1     NOT NULL,
    "date_created"    Timestamp With Time Zone DEFAULT NOW() NOT NULL,
    "date_updated"    Timestamp With Time Zone DEFAULT NOW() NOT NULL,
    "online"          Boolean                  DEFAULT false NOT NULL,
    "deleted"         Boolean                  DEFAULT false NOT NULL,
    CONSTRAINT "unique_cms_image_x_file_image_x_file_id" UNIQUE ("image_x_file_id")
);
;

-- brand - series - product - variant (taxonomy = non-editable, non-cms, centrally managed table)

DROP TABLE IF EXISTS "public"."cms_variant";
CREATE TABLE "public"."cms_variant"
(
    "variant_id"   Serial PRIMARY KEY,
    "instance_id"  Integer                  DEFAULT 0     NOT NULL,
    "product_id"   Integer                  DEFAULT 0     NOT NULL,
    "serie_id"     Integer                  DEFAULT 0     NOT NULL,
    "brand_id"     Integer                  DEFAULT 0     NOT NULL,
    "taxonomy_id"  Integer                  DEFAULT 0     NOT NULL,
    "title"        Character Varying(127)   DEFAULT ''    NOT NULL,
    "slug"         Character Varying(127)   DEFAULT ''    NOT NULL,
    "css_class"    Character varying(255)    DEFAULT ''    NOT NULL,
    "mpn"          Character Varying(127)   DEFAULT ''    NOT NULL,
    "sku"          Character Varying(127)   DEFAULT ''    NOT NULL,
    "upc"          CHAR(12)                 DEFAULT ''    NOT NULL,
    "ean"          CHAR(13)                 DEFAULT ''    NOT NULL,
    "price_from"   Character Varying(127)   DEFAULT ''    NOT NULL,
    "price"        Character Varying(127)   DEFAULT ''    NOT NULL,
    "in_stock"     Boolean                  DEFAULT true  NOT NULL,
    "excerpt"      Text,
    "content"      Text,
    "template"     Character Varying(40)    DEFAULT ''    NOT NULL,
    "date_created" Timestamp With Time Zone DEFAULT now() NOT NULL,
    "date_updated" Timestamp With Time Zone DEFAULT now() NOT NULL,
    "online"       Boolean                  DEFAULT false NOT NULL,
    "deleted"      Boolean                  DEFAULT false NOT NULL,
    CONSTRAINT "unique_cms_variant_variant_id" UNIQUE ("variant_id")
);

DROP TABLE IF EXISTS "public"."cms_product";
CREATE TABLE "public"."cms_product"
(
    product_id     SERIAL PRIMARY KEY,
    instance_id    Integer                                NOT NULL,
    serie_id       Integer                  DEFAULT 0     NOT NULL,
    brand_id       Integer                  DEFAULT 0     NOT NULL,
    "title"        Character Varying(127)   DEFAULT ''    NOT NULL,
    "slug"         Character Varying(127)   DEFAULT ''    NOT NULL,
    "css_class"    Character varying(255)    DEFAULT ''    NOT NULL,
    "excerpt"      Text,
    "content"      Text,
    "template"     Character Varying(40)    DEFAULT ''    NOT NULL,
    "date_created" Timestamp With Time Zone DEFAULT now() NOT NULL,
    "date_updated" Timestamp With Time Zone DEFAULT now() NOT NULL,
    "online"       Boolean                  DEFAULT false NOT NULL,
    "deleted"      Boolean                  DEFAULT false NOT NULL,
    CONSTRAINT "unique_cms_product_product_id" UNIQUE ("product_id")
);

DROP TABLE IF EXISTS "public"."cms_serie";
CREATE TABLE "public"."cms_serie"
(
    serie_id       SERIAL PRIMARY KEY,
    instance_id    Integer                                NOT NULL,
    brand_id       Integer                  DEFAULT 0     NOT NULL,
    "title"        Character Varying(127)   DEFAULT ''    NOT NULL,
    "slug"         Character Varying(127)   DEFAULT ''    NOT NULL,
    "css_class"    Character varying(255)    DEFAULT ''    NOT NULL,
    "excerpt"      Text,
    "content"      Text,
    "template"     Character Varying(40)    DEFAULT ''    NOT NULL,
    "date_created" Timestamp With Time Zone DEFAULT now() NOT NULL,
    "date_updated" Timestamp With Time Zone DEFAULT now() NOT NULL,
    "online"       Boolean                  DEFAULT false NOT NULL,
    "deleted"      Boolean                  DEFAULT false NOT NULL,
    CONSTRAINT "unique_cms_serie_serie_id" UNIQUE ("serie_id")
);

DROP TABLE IF EXISTS "public"."cms_brand";
CREATE TABLE "public"."cms_brand"
(
    brand_id       SERIAL PRIMARY KEY,
    instance_id    Integer                                NOT NULL,
    "brand_name"   Character Varying(127)   DEFAULT ''    NOT NULL,
    "title"        Character Varying(127)   DEFAULT ''    NOT NULL,
    "slug"         Character Varying(127)   DEFAULT ''    NOT NULL,
    "css_class"    Character varying(255)    DEFAULT ''    NOT NULL,
    "excerpt"      Text,
    "content"      Text,
    "template"     Character Varying(40)    DEFAULT ''    NOT NULL,
    "date_created" Timestamp With Time Zone DEFAULT now() NOT NULL,
    "date_updated" Timestamp With Time Zone DEFAULT now() NOT NULL,
    "online"       Boolean                  DEFAULT false NOT NULL,
    "deleted"      Boolean                  DEFAULT false NOT NULL,
    CONSTRAINT "unique_cms_brand_brand_id" UNIQUE ("brand_id")
);

-- add videos, images and files to brands, series, products and variants


-- add video to all new elements
DROP TABLE IF EXISTS "public"."cms_video_x_brand";
CREATE TABLE "public"."cms_video_x_brand"
(
    "video_x_brand_id" Serial PRIMARY KEY,
    "sub_video_id"     Integer                                NOT NULL,
    "brand_id"         Integer                                NOT NULL,
    "o"                SmallInt                 DEFAULT 1     NOT NULL,
    "sub_o"            SmallInt                 DEFAULT 1     NOT NULL,
    "date_created"     Timestamp With Time Zone DEFAULT NOW() NOT NULL,
    "date_updated"     Timestamp With Time Zone DEFAULT NOW() NOT NULL,
    "online"           Boolean                  DEFAULT false NOT NULL,
    "deleted"          Boolean                  DEFAULT false NOT NULL,
    CONSTRAINT "unique_cms_video_x_brand_video_x_brand_id" UNIQUE ("video_x_brand_id")
);
;

DROP TABLE IF EXISTS "public"."cms_video_x_serie";
CREATE TABLE "public"."cms_video_x_serie"
(
    "video_x_serie_id" Serial PRIMARY KEY,
    "sub_video_id"     Integer                                NOT NULL,
    "serie_id"         Integer                                NOT NULL,
    "o"                SmallInt                 DEFAULT 1     NOT NULL,
    "sub_o"            SmallInt                 DEFAULT 1     NOT NULL,
    "date_created"     Timestamp With Time Zone DEFAULT NOW() NOT NULL,
    "date_updated"     Timestamp With Time Zone DEFAULT NOW() NOT NULL,
    "online"           Boolean                  DEFAULT false NOT NULL,
    "deleted"          Boolean                  DEFAULT false NOT NULL,
    CONSTRAINT "unique_cms_video_x_serie_video_x_serie_id" UNIQUE ("video_x_serie_id")
);
;

DROP TABLE IF EXISTS "public"."cms_video_x_product";
CREATE TABLE "public"."cms_video_x_product"
(
    "video_x_product_id" Serial PRIMARY KEY,
    "sub_video_id"       Integer                                NOT NULL,
    "product_id"         Integer                                NOT NULL,
    "o"                  SmallInt                 DEFAULT 1     NOT NULL,
    "sub_o"              SmallInt                 DEFAULT 1     NOT NULL,
    "date_created"       Timestamp With Time Zone DEFAULT NOW() NOT NULL,
    "date_updated"       Timestamp With Time Zone DEFAULT NOW() NOT NULL,
    "online"             Boolean                  DEFAULT false NOT NULL,
    "deleted"            Boolean                  DEFAULT false NOT NULL,
    CONSTRAINT "unique_cms_video_x_product_video_x_product_id" UNIQUE ("video_x_product_id")
);
;

DROP TABLE IF EXISTS "public"."cms_video_x_variant";
CREATE TABLE "public"."cms_video_x_variant"
(
    "video_x_variant_id" Serial PRIMARY KEY,
    "sub_video_id"       Integer                                NOT NULL,
    "variant_id"         Integer                                NOT NULL,
    "o"                  SmallInt                 DEFAULT 1     NOT NULL,
    "sub_o"              SmallInt                 DEFAULT 1     NOT NULL,
    "date_created"       Timestamp With Time Zone DEFAULT NOW() NOT NULL,
    "date_updated"       Timestamp With Time Zone DEFAULT NOW() NOT NULL,
    "online"             Boolean                  DEFAULT false NOT NULL,
    "deleted"            Boolean                  DEFAULT false NOT NULL,
    CONSTRAINT "unique_cms_video_x_variant_video_x_variant_id" UNIQUE ("video_x_variant_id")
);
;

-- add image to all new elements
DROP TABLE IF EXISTS "public"."cms_image_x_brand";
CREATE TABLE "public"."cms_image_x_brand"
(
    "image_x_brand_id" Serial PRIMARY KEY,
    "sub_image_id"     Integer                                NOT NULL,
    "brand_id"         Integer                                NOT NULL,
    "o"                SmallInt                 DEFAULT 1     NOT NULL,
    "sub_o"            SmallInt                 DEFAULT 1     NOT NULL,
    "date_created"     Timestamp With Time Zone DEFAULT NOW() NOT NULL,
    "date_updated"     Timestamp With Time Zone DEFAULT NOW() NOT NULL,
    "online"           Boolean                  DEFAULT false NOT NULL,
    "deleted"          Boolean                  DEFAULT false NOT NULL,
    CONSTRAINT "unique_cms_image_x_brand_image_x_brand_id" UNIQUE ("image_x_brand_id")
);
;

DROP TABLE IF EXISTS "public"."cms_image_x_serie";
CREATE TABLE "public"."cms_image_x_serie"
(
    "image_x_serie_id" Serial PRIMARY KEY,
    "sub_image_id"     Integer                                NOT NULL,
    "serie_id"         Integer                                NOT NULL,
    "o"                SmallInt                 DEFAULT 1     NOT NULL,
    "sub_o"            SmallInt                 DEFAULT 1     NOT NULL,
    "date_created"     Timestamp With Time Zone DEFAULT NOW() NOT NULL,
    "date_updated"     Timestamp With Time Zone DEFAULT NOW() NOT NULL,
    "online"           Boolean                  DEFAULT false NOT NULL,
    "deleted"          Boolean                  DEFAULT false NOT NULL,
    CONSTRAINT "unique_cms_image_x_serie_image_x_serie_id" UNIQUE ("image_x_serie_id")
);
;

DROP TABLE IF EXISTS "public"."cms_image_x_product";
CREATE TABLE "public"."cms_image_x_product"
(
    "image_x_product_id" Serial PRIMARY KEY,
    "sub_image_id"       Integer                                NOT NULL,
    "product_id"         Integer                                NOT NULL,
    "o"                  SmallInt                 DEFAULT 1     NOT NULL,
    "sub_o"              SmallInt                 DEFAULT 1     NOT NULL,
    "date_created"       Timestamp With Time Zone DEFAULT NOW() NOT NULL,
    "date_updated"       Timestamp With Time Zone DEFAULT NOW() NOT NULL,
    "online"             Boolean                  DEFAULT false NOT NULL,
    "deleted"            Boolean                  DEFAULT false NOT NULL,
    CONSTRAINT "unique_cms_image_x_product_image_x_product_id" UNIQUE ("image_x_product_id")
);
;

DROP TABLE IF EXISTS "public"."cms_image_x_variant";
CREATE TABLE "public"."cms_image_x_variant"
(
    "image_x_variant_id" Serial PRIMARY KEY,
    "sub_image_id"       Integer                                NOT NULL,
    "variant_id"         Integer                                NOT NULL,
    "o"                  SmallInt                 DEFAULT 1     NOT NULL,
    "sub_o"              SmallInt                 DEFAULT 1     NOT NULL,
    "date_created"       Timestamp With Time Zone DEFAULT NOW() NOT NULL,
    "date_updated"       Timestamp With Time Zone DEFAULT NOW() NOT NULL,
    "online"             Boolean                  DEFAULT false NOT NULL,
    "deleted"            Boolean                  DEFAULT false NOT NULL,
    CONSTRAINT "unique_cms_image_x_variant_image_x_variant_id" UNIQUE ("image_x_variant_id")
);
;

-- add file to all new elements
DROP TABLE IF EXISTS "public"."cms_file_x_brand";
CREATE TABLE "public"."cms_file_x_brand"
(
    "file_x_brand_id" Serial PRIMARY KEY,
    "sub_file_id"     Integer                                NOT NULL,
    "brand_id"        Integer                                NOT NULL,
    "o"               SmallInt                 DEFAULT 1     NOT NULL,
    "sub_o"           SmallInt                 DEFAULT 1     NOT NULL,
    "date_created"    Timestamp With Time Zone DEFAULT NOW() NOT NULL,
    "date_updated"    Timestamp With Time Zone DEFAULT NOW() NOT NULL,
    "online"          Boolean                  DEFAULT false NOT NULL,
    "deleted"         Boolean                  DEFAULT false NOT NULL,
    CONSTRAINT "unique_cms_file_x_brand_file_x_brand_id" UNIQUE ("file_x_brand_id")
);
;

DROP TABLE IF EXISTS "public"."cms_file_x_serie";
CREATE TABLE "public"."cms_file_x_serie"
(
    "file_x_serie_id" Serial PRIMARY KEY,
    "sub_file_id"     Integer                                NOT NULL,
    "serie_id"        Integer                                NOT NULL,
    "o"               SmallInt                 DEFAULT 1     NOT NULL,
    "sub_o"           SmallInt                 DEFAULT 1     NOT NULL,
    "date_created"    Timestamp With Time Zone DEFAULT NOW() NOT NULL,
    "date_updated"    Timestamp With Time Zone DEFAULT NOW() NOT NULL,
    "online"          Boolean                  DEFAULT false NOT NULL,
    "deleted"         Boolean                  DEFAULT false NOT NULL,
    CONSTRAINT "unique_cms_file_x_serie_file_x_serie_id" UNIQUE ("file_x_serie_id")
);
;

DROP TABLE IF EXISTS "public"."cms_file_x_product";
CREATE TABLE "public"."cms_file_x_product"
(
    "file_x_product_id" Serial PRIMARY KEY,
    "sub_file_id"       Integer                                NOT NULL,
    "product_id"        Integer                                NOT NULL,
    "o"                 SmallInt                 DEFAULT 1     NOT NULL,
    "sub_o"             SmallInt                 DEFAULT 1     NOT NULL,
    "date_created"      Timestamp With Time Zone DEFAULT NOW() NOT NULL,
    "date_updated"      Timestamp With Time Zone DEFAULT NOW() NOT NULL,
    "online"            Boolean                  DEFAULT false NOT NULL,
    "deleted"           Boolean                  DEFAULT false NOT NULL,
    CONSTRAINT "unique_cms_file_x_product_file_x_product_id" UNIQUE ("file_x_product_id")
);
;

DROP TABLE IF EXISTS "public"."cms_file_x_variant";
CREATE TABLE "public"."cms_file_x_variant"
(
    "file_x_variant_id" Serial PRIMARY KEY,
    "sub_file_id"       Integer                                NOT NULL,
    "variant_id"        Integer                                NOT NULL,
    "o"                 SmallInt                 DEFAULT 1     NOT NULL,
    "sub_o"             SmallInt                 DEFAULT 1     NOT NULL,
    "date_created"      Timestamp With Time Zone DEFAULT NOW() NOT NULL,
    "date_updated"      Timestamp With Time Zone DEFAULT NOW() NOT NULL,
    "online"            Boolean                  DEFAULT false NOT NULL,
    "deleted"           Boolean                  DEFAULT false NOT NULL,
    CONSTRAINT "unique_cms_file_x_variant_file_x_variant_id" UNIQUE ("file_x_variant_id")
);
;

COMMIT;

-- version 0.4.15

BEGIN;

-- for this update we wipe the history database and create it anew

-- wipe history <- this comment triggers the wipe history command in the update process

-- rename video to embed...

DO
$$
    BEGIN
        IF EXISTS(SELECT *
                  FROM information_schema.columns
                  WHERE table_name = 'cms_video'
                    and column_name = 'video_id')
        THEN
            ALTER TABLE "public"."cms_video"
                RENAME COLUMN "video_id" TO "embed_id";
        END IF;
    END
$$;
DO
$$
    BEGIN
        IF EXISTS(SELECT *
                  FROM information_schema.columns
                  WHERE table_name = 'cms_video'
                    and column_name = 'embed')
        THEN
            ALTER TABLE "public"."cms_video"
                RENAME COLUMN "embed" TO "embed_code";
        END IF;
    END
$$;

-- drop the embed tables if this is a re-install
DROP TABLE IF EXISTS "public"."cms_embed";
DROP TABLE IF EXISTS "public"."cms_embed_x_brand";
DROP TABLE IF EXISTS "public"."cms_embed_x_page";
DROP TABLE IF EXISTS "public"."cms_embed_x_product";
DROP TABLE IF EXISTS "public"."cms_embed_x_serie";
DROP TABLE IF EXISTS "public"."cms_embed_x_variant";


ALTER TABLE IF EXISTS "public"."cms_video"
    RENAME TO "cms_embed";
-- including index and default value for id
ALTER INDEX IF EXISTS unique_cms_video_video_id RENAME TO unique_cms_embed_embed_id;
ALTER SEQUENCE IF EXISTS cms_video_video_id_seq RENAME TO cms_embed_embed_id_seq;

-- including all the referencing tables
ALTER TABLE IF EXISTS cms_video_x_brand
    RENAME TO cms_embed_x_brand;
ALTER TABLE IF EXISTS cms_video_x_page
    RENAME TO cms_embed_x_page;
ALTER TABLE IF EXISTS cms_video_x_product
    RENAME TO cms_embed_x_product;
ALTER TABLE IF EXISTS cms_video_x_serie
    RENAME TO cms_embed_x_serie;
ALTER TABLE IF EXISTS cms_video_x_variant
    RENAME TO cms_embed_x_variant;

-- update all the referencing tables id's
DO
$$
    BEGIN
        IF EXISTS(SELECT *
                  FROM information_schema.columns
                  WHERE table_name = 'cms_embed_x_brand'
                    and column_name = 'sub_video_id')
        THEN
            ALTER TABLE "public"."cms_embed_x_brand"
                RENAME COLUMN "sub_video_id" TO "sub_embed_id";
        END IF;
    END
$$;
DO
$$
    BEGIN
        IF EXISTS(SELECT *
                  FROM information_schema.columns
                  WHERE table_name = 'cms_embed_x_brand'
                    and column_name = 'video_x_brand_id')
        THEN
            ALTER TABLE "public"."cms_embed_x_brand"
                RENAME COLUMN "video_x_brand_id" TO "embed_x_brand_id";
        END IF;
    END
$$;
ALTER INDEX IF EXISTS unique_cms_video_x_brand_video_x_brand_id RENAME TO unique_cms_embed_x_brand_embed_x_brand_id;
ALTER SEQUENCE IF EXISTS cms_video_x_brand_video_x_brand_id_seq RENAME TO cms_embed_x_brand_embed_x_brand_id_seq;
------------------------------------------------------------------------------------------------------------------------
DO
$$
    BEGIN
        IF EXISTS(SELECT *
                  FROM information_schema.columns
                  WHERE table_name = 'cms_embed_x_page'
                    and column_name = 'sub_video_id')
        THEN
            ALTER TABLE "public"."cms_embed_x_page"
                RENAME COLUMN "sub_video_id" TO "sub_embed_id";
        END IF;
    END
$$;
DO
$$
    BEGIN
        IF EXISTS(SELECT *
                  FROM information_schema.columns
                  WHERE table_name = 'cms_embed_x_page'
                    and column_name = 'video_x_page_id')
        THEN
            ALTER TABLE "public"."cms_embed_x_page"
                RENAME COLUMN "video_x_page_id" TO "embed_x_page_id";
        END IF;
    END
$$;
ALTER INDEX IF EXISTS unique_cms_video_x_page_video_x_page_id RENAME TO unique_cms_embed_x_page_embed_x_page_id;
ALTER SEQUENCE IF EXISTS cms_video_x_page_video_x_page_id_seq RENAME TO cms_embed_x_page_embed_x_page_id_seq;
------------------------------------------------------------------------------------------------------------------------
DO
$$
    BEGIN
        IF EXISTS(SELECT *
                  FROM information_schema.columns
                  WHERE table_name = 'cms_embed_x_product'
                    and column_name = 'sub_video_id')
        THEN
            ALTER TABLE "public"."cms_embed_x_product"
                RENAME COLUMN "sub_video_id" TO "sub_embed_id";
        END IF;
    END
$$;
DO
$$
    BEGIN
        IF EXISTS(SELECT *
                  FROM information_schema.columns
                  WHERE table_name = 'cms_embed_x_product'
                    and column_name = 'video_x_product_id')
        THEN
            ALTER TABLE "public"."cms_embed_x_product"
                RENAME COLUMN "video_x_product_id" TO "embed_x_product_id";
        END IF;
    END
$$;
ALTER INDEX IF EXISTS unique_cms_video_x_product_video_x_product_id RENAME TO unique_cms_embed_x_product_embed_x_product_id;
ALTER SEQUENCE IF EXISTS cms_video_x_product_video_x_product_id_seq RENAME TO cms_embed_x_product_embed_x_product_id_seq;
------------------------------------------------------------------------------------------------------------------------
DO
$$
    BEGIN
        IF EXISTS(SELECT *
                  FROM information_schema.columns
                  WHERE table_name = 'cms_embed_x_serie'
                    and column_name = 'sub_video_id')
        THEN
            ALTER TABLE "public"."cms_embed_x_serie"
                RENAME COLUMN "sub_video_id" TO "sub_embed_id";
        END IF;
    END
$$;
DO
$$
    BEGIN
        IF EXISTS(SELECT *
                  FROM information_schema.columns
                  WHERE table_name = 'cms_embed_x_serie'
                    and column_name = 'video_x_serie_id')
        THEN
            ALTER TABLE "public"."cms_embed_x_serie"
                RENAME COLUMN "video_x_serie_id" TO "embed_x_serie_id";
        END IF;
    END
$$;
ALTER INDEX IF EXISTS unique_cms_video_x_serie_video_x_serie_id RENAME TO unique_cms_embed_x_serie_embed_x_serie_id;
ALTER SEQUENCE IF EXISTS cms_video_x_serie_video_x_serie_id_seq RENAME TO cms_embed_x_serie_embed_x_serie_id_seq;
------------------------------------------------------------------------------------------------------------------------
DO
$$
    BEGIN
        IF EXISTS(SELECT *
                  FROM information_schema.columns
                  WHERE table_name = 'cms_embed_x_variant'
                    and column_name = 'sub_video_id')
        THEN
            ALTER TABLE "public"."cms_embed_x_variant"
                RENAME COLUMN "sub_video_id" TO "sub_embed_id";
        END IF;
    END
$$;
DO
$$
    BEGIN
        IF EXISTS(SELECT *
                  FROM information_schema.columns
                  WHERE table_name = 'cms_embed_x_variant'
                    and column_name = 'video_x_variant_id')
        THEN
            ALTER TABLE "public"."cms_embed_x_variant"
                RENAME COLUMN "video_x_variant_id" TO "embed_x_variant_id";
        END IF;
    END
$$;
ALTER INDEX IF EXISTS unique_cms_video_x_variant_video_x_variant_id RENAME TO unique_cms_embed_x_variant_embed_x_variant_id;
ALTER SEQUENCE IF EXISTS cms_video_x_variant_video_x_variant_id_seq RENAME TO cms_embed_x_variant_embed_x_variant_id_seq;
------------------------------------------------------------------------------------------------------------------------


-- the template editor / management interface etc.

-- CREATE TABLE "_template" -------------------------------------
DROP TABLE IF EXISTS "public"."_template";
CREATE TABLE "public"."_template"
(
    "template_id"            Serial PRIMARY KEY,
    "instance_id"            Integer                                NOT NULL,
    "name"                   Character Varying(127)   DEFAULT ''    NOT NULL,
    "element"                Character Varying(40)    DEFAULT ''    NOT NULL,
    "html"                   Text,
    "json_prepared"          Text,
    "nested_max"             SmallInt                 DEFAULT 2     NOT NULL,
    "nested_show_first_only" Boolean                  DEFAULT false NOT NULL,
    "date_created"           Timestamp With Time Zone DEFAULT NOW() NOT NULL,
    "date_updated"           Timestamp With Time Zone DEFAULT NOW() NOT NULL,
    "published"              Boolean                  DEFAULT false NOT NULL,
    "deleted"                Boolean                  DEFAULT false NOT NULL,
    CONSTRAINT "unique__template_template_id" UNIQUE ("template_id")
);
;

-- add template_id to all element tables
ALTER TABLE "public"."cms_brand"
    ADD COLUMN if not exists "template_id" INTEGER DEFAULT 0 NOT NULL;
ALTER TABLE "public"."cms_file"
    ADD COLUMN if not exists "template_id" INTEGER DEFAULT 0 NOT NULL;
ALTER TABLE "public"."cms_image"
    ADD COLUMN if not exists "template_id" INTEGER DEFAULT 0 NOT NULL;
ALTER TABLE "public"."cms_menu"
    ADD COLUMN if not exists "template_id" INTEGER DEFAULT 0 NOT NULL;
ALTER TABLE "public"."cms_page"
    ADD COLUMN if not exists "template_id" INTEGER DEFAULT 0 NOT NULL;
ALTER TABLE "public"."cms_product"
    ADD COLUMN if not exists "template_id" INTEGER DEFAULT 0 NOT NULL;
ALTER TABLE "public"."cms_serie"
    ADD COLUMN if not exists "template_id" INTEGER DEFAULT 0 NOT NULL;
ALTER TABLE "public"."cms_variant"
    ADD COLUMN if not exists "template_id" INTEGER DEFAULT 0 NOT NULL;
ALTER TABLE "public"."cms_embed"
    ADD COLUMN if not exists "template_id" INTEGER DEFAULT 0 NOT NULL;

COMMIT;

-- version 0.5.0

BEGIN;

-- rebuild cache database

COMMIT;

-- version 0.5.1

BEGIN;


-- CREATE TABLE "_shoppinglist" ----------------------------------------
DROP TABLE IF EXISTS "public"."_shoppinglist";
CREATE TABLE "public"."_shoppinglist"
(
    "shoppinglist_id" Serial PRIMARY KEY,
    "instance_id"     Integer                                NOT NULL,
    "session_id"      Integer                  DEFAULT 0     NOT NULL,
    "user_id"         Integer                  DEFAULT 0     NOT NULL,
    "name"            Character Varying(40)    DEFAULT ''    NOT NULL,
    "remarks_user"    Text,
    "remarks_admin"   Text,
    "date_created"    Timestamp With Time Zone DEFAULT NOW() NOT NULL,
    "date_updated"    Timestamp With Time Zone DEFAULT NOW() NOT NULL,
    "deleted"         Boolean                  DEFAULT false NOT NULL,
    CONSTRAINT "unique__shoppinglist_shoppinglist_id" UNIQUE ("shoppinglist_id")
);
;

-- CREATE TABLE "_shoppinglist_variant" --------------------------------
DROP TABLE IF EXISTS "public"."_shoppinglist_variant";
CREATE TABLE "public"."_shoppinglist_variant"
(
    "shoppinglist_variant_id" BIGSERIAL PRIMARY KEY,
    "shoppinglist_id"         Integer                                NOT NULL,
    "variant_id"              Integer                  DEFAULT 0     NOT NULL,
    "quantity"                SmallInt                 DEFAULT 1     NOT NULL,
    "price"                   Character Varying(127)   DEFAULT '0'   NOT NULL,
    "price_from"              Character Varying(127)   DEFAULT '0'   NOT NULL,
    "o"                       SmallInt                 DEFAULT 1     NOT NULL,
    "date_created"            Timestamp With Time Zone DEFAULT NOW() NOT NULL,
    "date_updated"            Timestamp With Time Zone DEFAULT NOW() NOT NULL,
    "deleted"                 Boolean                  DEFAULT false NOT NULL,
    CONSTRAINT "unique__shoppinglist_variant_shoppinglist_variant_id" UNIQUE ("shoppinglist_variant_id")
);
;

DROP TABLE IF EXISTS "public"."_list";
DROP TABLE IF EXISTS "public"."_list_variant";

COMMIT;

-- version 0.5.2

BEGIN;

-- add some presentation settings to instance

ALTER TABLE "public"."_instance"
    ADD COLUMN if not exists "decimal_separator" Character Varying(1) DEFAULT ',' NOT NULL;
ALTER TABLE "public"."_instance"
    ADD COLUMN if not exists "decimal_digits" SMALLINT DEFAULT 2 NOT NULL;

COMMIT;

-- version 0.5.3
BEGIN;
-- no database changes
COMMIT;

-- version 0.5.4

BEGIN;

ALTER TABLE "public"."cms_page"
    DROP COLUMN if exists "template";

COMMIT;

-- version 0.5.5

BEGIN;

ALTER TABLE "public"."_template"
    ALTER COLUMN html SET DEFAULT '';
ALTER TABLE "public"."_template"
    ALTER COLUMN json_prepared SET DEFAULT '';

COMMIT;

-- version 0.5.6
BEGIN;
-- no database changes
COMMIT;
-- version 0.5.7
BEGIN;
-- no database changes
COMMIT;
-- version 0.5.8
BEGIN;
-- no database changes
COMMIT;
-- version 0.5.9
BEGIN;
-- no database changes
COMMIT;
-- version 0.5.10
BEGIN;

ALTER TABLE _instance
    ADD COLUMN if not exists mailgun_custom_domain Character Varying(127) Default '' NOT NULL;
ALTER TABLE _instance
    ADD COLUMN if not exists mail_verified_sender Character Varying(127) Default '' NOT NULL;
ALTER TABLE _instance
    ADD COLUMN if not exists recaptcha_site_key Character Varying(127) Default '' NOT NULL;
ALTER TABLE _instance
    ADD COLUMN if not exists recaptcha_secret_key Character Varying(127) Default '' NOT NULL;
ALTER TABLE _instance
    ADD COLUMN if not exists recaptcha_pass_score Character Varying(10) Default '0.5' NOT NULL;

-- recreate shoppinglist variant with a larger id column (pending refactoring peat to work without id's there)
-- CREATE TABLE "_shoppinglist_variant" --------------------------------
DROP TABLE IF EXISTS "public"."_shoppinglist_variant";
CREATE TABLE "public"."_shoppinglist_variant"
(
    "shoppinglist_variant_id" BIGSERIAL PRIMARY KEY,
    "shoppinglist_id"         Integer                                NOT NULL,
    "variant_id"              Integer                  DEFAULT 0     NOT NULL,
    "quantity"                SmallInt                 DEFAULT 1     NOT NULL,
    "price"                   Character Varying(127)   DEFAULT '0'   NOT NULL,
    "price_from"              Character Varying(127)   DEFAULT '0'   NOT NULL,
    "o"                       SmallInt                 DEFAULT 1     NOT NULL,
    "date_created"            Timestamp With Time Zone DEFAULT NOW() NOT NULL,
    "date_updated"            Timestamp With Time Zone DEFAULT NOW() NOT NULL,
    "deleted"                 Boolean                  DEFAULT false NOT NULL,
    CONSTRAINT "unique__shoppinglist_variant_shoppinglist_variant_id" UNIQUE ("shoppinglist_variant_id")
);
;

-- CREATE TABLE "_order_variant" ---------------------------------------
DROP TABLE IF EXISTS "public"."_order_variant";
CREATE TABLE "public"."_order_variant"
(
    "order_variant_id" BIGSERIAL PRIMARY KEY,
    "order_id"         Integer                                NOT NULL,
    "variant_id"       Integer                  DEFAULT 0     NOT NULL,
    "quantity"         SmallInt                 DEFAULT 1     NOT NULL,
    "price"            Character Varying(127)   DEFAULT '0'   NOT NULL,
    "price_from"       Character Varying(127)   DEFAULT '0'   NOT NULL,
    "o"                SmallInt                 DEFAULT 1     NOT NULL,
    "date_created"     Timestamp With Time Zone DEFAULT NOW() NOT NULL,
    "date_updated"     Timestamp With Time Zone DEFAULT NOW() NOT NULL,
    "deleted"          Boolean                  DEFAULT false NOT NULL,
    CONSTRAINT "unique__order_variant_order_variant_id" UNIQUE ("order_variant_id")
);
;

-- CREATE TABLE "_country" ---------------------------------------
DROP TABLE IF EXISTS "public"."_country";
CREATE TABLE "public"."_country"
(
    "country_id"         SERIAL PRIMARY KEY,
    "instance_id"        Integer                                NOT NULL,
    "name"               Character Varying(127)   DEFAULT ''    NOT NULL,
    "iso2"               Character Varying(2)     DEFAULT ''    NOT NULL,
    "iso3"               Character Varying(3)     DEFAULT ''    NOT NULL,
    "shipping_costs"     Character Varying(127)   DEFAULT '0'   NOT NULL,
    "shipping_free_from" Character Varying(127)   DEFAULT '0'   NOT NULL,
    "o"                  SmallInt                 DEFAULT 1     NOT NULL,
    "date_created"       Timestamp With Time Zone DEFAULT NOW() NOT NULL,
    "date_updated"       Timestamp With Time Zone DEFAULT NOW() NOT NULL,
    "deleted"            Boolean                  DEFAULT false NOT NULL,
    CONSTRAINT "unique__country_country_id" UNIQUE ("country_id")
);
;

-- link images to embed type
-- CREATE TABLE "cms_image_x_embed" ----------------------------
DROP TABLE IF EXISTS "public"."cms_image_x_embed";
CREATE TABLE "public"."cms_image_x_embed"
(
    "image_x_embed_id" Serial PRIMARY KEY,
    "sub_image_id"     Integer                                NOT NULL,
    "embed_id"         Integer                                NOT NULL,
    "o"                SmallInt                 DEFAULT 1     NOT NULL,
    "sub_o"            SmallInt                 DEFAULT 1     NOT NULL,
    "date_created"     Timestamp With Time Zone DEFAULT NOW() NOT NULL,
    "date_updated"     Timestamp With Time Zone DEFAULT NOW() NOT NULL,
    "online"           Boolean                  DEFAULT false NOT NULL,
    "deleted"          Boolean                  DEFAULT false NOT NULL,
    CONSTRAINT "unique_cms_image_x_embed_image_x_embed_id" UNIQUE ("image_x_embed_id")
);
;
COMMIT;
-- version 0.5.11
BEGIN;

ALTER TABLE _instance
    ADD COLUMN if not exists mail_default_receiver Character Varying(127) Default '' NOT NULL;

COMMIT;
-- version 0.5.12
BEGIN;

-- CREATE TABLE "_order" ---- new version -----------------------------
DROP TABLE IF EXISTS "public"."_order";
CREATE TABLE "public"."_order"
(
    "order_id"                         Serial PRIMARY KEY,
    "instance_id"                      Integer                                NOT NULL,
    "session_id"                       Integer                  DEFAULT 0     NOT NULL,
    "user_id"                          Integer                  DEFAULT 0     NOT NULL,
    "user_email"                       Character Varying(127)                 NOT NULL,
    "order_number"                     Character Varying(127)                 NOT NULL,
    "amount_row_total"                 Integer                  DEFAULT 0     NOT NULL,
    "shipping_costs"                   Integer                  DEFAULT 0     NOT NULL,
    "amount_grand_total"               Integer                  DEFAULT 0     NOT NULL,
    "payment_confirmed_bool"           Boolean                  DEFAULT false NOT NULL,
    "payment_confirmed_text"           Text,
    "payment_confirmed_date"           Timestamp With Time Zone,
    "emailed_order_confirmation"       Boolean                  DEFAULT false NOT NULL,
    "billing_address_name"             Character Varying(127),
    "billing_address_company"          Character Varying(127),
    "billing_address_postal_code"      Character Varying(127),
    "billing_address_number"           Character Varying(127),
    "billing_address_number_addition"  Character Varying(127),
    "billing_address_street"           Character Varying(127),
    "billing_address_street_addition"  Character Varying(127),
    "billing_address_city"             Character Varying(127),
    "billing_address_country_name"     Character Varying(127),
    "billing_address_country_iso2"     Character Varying(2),
    "billing_address_country_iso3"     Character Varying(3),
    "shipping_address_name"            Character Varying(127),
    "shipping_address_company"         Character Varying(127),
    "shipping_address_postal_code"     Character Varying(127),
    "shipping_address_number"          Character Varying(127),
    "shipping_address_number_addition" Character Varying(127),
    "shipping_address_street"          Character Varying(127),
    "shipping_address_street_addition" Character Varying(127),
    "shipping_address_city"            Character Varying(127),
    "shipping_address_country_name"    Character Varying(127),
    "shipping_address_country_iso2"    Character Varying(2),
    "shipping_address_country_iso3"    Character Varying(3),
    "remarks_user"                     Text,
    "remarks_admin"                    Text,
    "date_created"                     Timestamp With Time Zone DEFAULT NOW() NOT NULL,
    "date_updated"                     Timestamp With Time Zone DEFAULT NOW() NOT NULL,
    "deleted"                          Boolean                  DEFAULT false NOT NULL,
    CONSTRAINT "unique__order_order_id" UNIQUE ("order_id")
);
;

-- CREATE TABLE "_order_number" ---- strictly to enforce unique order numbers ----------------
DROP TABLE IF EXISTS "public"."_order_number";
CREATE TABLE "public"."_order_number"
(
    "instance_id"  Integer                NOT NULL,
    "order_number" Character Varying(127) NOT NULL,
    CONSTRAINT "unique__order_number" UNIQUE ("instance_id", "order_number") -- order_number must be unique for the instance
);
;

-- Add the ‘up’voted popvote date to the variant element used for sorting
ALTER TABLE "public"."cms_variant"
    ADD COLUMN if not exists "date_popvote" Timestamp With Time Zone DEFAULT now() NOT NULL;

-- Add the times to sessionvars to prevent overwriting values that were stuck on the internet somewhere
ALTER TABLE "public"."_sessionvars"
    ADD COLUMN if not exists "times" Int DEFAULT 1 NOT NULL;

COMMIT;

-- version 0.5.14

BEGIN;
-- no database changes
COMMIT;

-- version 0.5.15

BEGIN;
update cms_product
set online = TRUE;
update cms_variant
set online = TRUE;

COMMIT;

-- version 0.5.16
BEGIN;

ALTER TABLE _order
    ADD COLUMN if not exists preferred_delivery_day Character Varying(127) Default '' NOT NULL;

ALTER TABLE _order
    ADD COLUMN if not exists html Text;

ALTER TABLE _order
    ADD COLUMN if not exists emailed_order_confirmation_response Text;

ALTER TABLE _order_variant
    ADD COLUMN if not exists title Character Varying(127) Default '' NOT NULL;
ALTER TABLE _order_variant
    ADD COLUMN if not exists mpn Character Varying(127) DEFAULT '' NOT NULL;
ALTER TABLE _order_variant
    ADD COLUMN if not exists sku Character Varying(127) DEFAULT '' NOT NULL;
ALTER TABLE _order_variant
    ADD COLUMN if not exists upc CHAR(12) DEFAULT '' NOT NULL;
ALTER TABLE _order_variant
    ADD COLUMN if not exists ean CHAR(13) DEFAULT '' NOT NULL;

COMMIT;

-- version 0.5.17

BEGIN;
-- no database changes
COMMIT;

-- version 0.5.18
BEGIN;

ALTER TABLE _sessionvars
    ALTER COLUMN value SET DATA TYPE Text;

ALTER TABLE _order
    ADD COLUMN if not exists user_phone Character Varying(127) DEFAULT '' NOT NULL;

UPDATE _order
SET emailed_order_confirmation = TRUE;

ALTER TABLE _instance
    ADD COLUMN if not exists template_id_order_confirmation Int DEFAULT 0 NOT NULL;

ALTER TABLE cms_variant
    DROP COLUMN if exists template;

ALTER TABLE cms_variant
    ADD COLUMN if not exists message Character Varying(127);

COMMIT;

-- version 0.6.0
BEGIN;

-- move cache tables to main database
DROP TABLE IF EXISTS "public"."_cache";
CREATE TABLE "public"."_cache"
(
    "instance_id" Integer                                NOT NULL,
    "row_as_json" Text                                   NOT NULL,
    "since"       Timestamp With Time Zone DEFAULT now() NOT NULL,
    "slug"        Character Varying(127)                 NOT NULL,
    "type_name"   Character Varying(127),
    "id"          Integer
);
CREATE INDEX "index_slug_cache" ON "public"."_cache" USING btree ("slug" Asc NULLS Last);
DROP TABLE IF EXISTS "public"."_stale";
CREATE TABLE "public"."_stale"
(
    "slug"        Character Varying(127)                 NOT NULL,
    "instance_id" Integer                                NOT NULL,
    "since"       Timestamp With Time Zone DEFAULT now() NOT NULL
);
CREATE INDEX "index_slug_stale" ON "public"."_stale" USING btree ("slug" Asc NULLS Last);


-- link table to add variants to a page
-- CREATE TABLE "cms_variant_x_page" ----------------------------
DROP TABLE IF EXISTS "public"."cms_variant_x_page";
CREATE TABLE "public"."cms_variant_x_page"
(
    "variant_x_page_id" Serial PRIMARY KEY,
    "sub_variant_id"    Integer                                NOT NULL,
    "page_id"           Integer                                NOT NULL,
    "o"                 SmallInt                 DEFAULT 1     NOT NULL,
    "sub_o"             SmallInt                 DEFAULT 1     NOT NULL,
    "date_created"      Timestamp With Time Zone DEFAULT NOW() NOT NULL,
    "date_updated"      Timestamp With Time Zone DEFAULT NOW() NOT NULL,
    "online"            Boolean                  DEFAULT false NOT NULL,
    "deleted"           Boolean                  DEFAULT false NOT NULL,
    CONSTRAINT "unique_cms_variant_x_page_variant_x_page_id" UNIQUE ("variant_x_page_id")
);


COMMIT;

-- version 0.6.1
BEGIN;

-- raw saving of payment status info
DROP TABLE IF EXISTS "public"."_payment_status_update";
CREATE TABLE "public"."_payment_status_update"
(
    "instance_id"    Integer                                NOT NULL,
    "order_number"   Character Varying(127),
    "json_raw"       Text,
    "origin"         Text,
    "bool_processed" Boolean                  DEFAULT false NOT NULL,
    "date_processed" Timestamp With Time Zone,
    "date_created"   Timestamp With Time Zone DEFAULT now() NOT NULL
);

-- payment service provider data
DROP TABLE IF EXISTS "public"."_payment_service_provider";
CREATE TABLE "public"."_payment_service_provider"
(
    "payment_service_provider_id" Serial PRIMARY KEY,
    "instance_id"                 Integer                                NOT NULL,
    "given_name"                  Character Varying(127),
    "provider_name"               Character Varying(40),
    "field_values"                Text,
    "date_created"                Timestamp With Time Zone DEFAULT NOW() NOT NULL,
    "date_updated"                Timestamp With Time Zone DEFAULT NOW() NOT NULL,
    "online"                      Boolean                  DEFAULT false NOT NULL,
    "deleted"                     Boolean                  DEFAULT false NOT NULL,
    CONSTRAINT "unique_payment_service_provider_id" UNIQUE ("payment_service_provider_id")
);


-- choose an active psp for the instance
ALTER TABLE "public"."_instance"
    ADD COLUMN if not exists "payment_service_provider_id" int DEFAULT 0 NOT NULL;

ALTER TABLE "public"."_instance"
    ADD COLUMN if not exists "confirmation_before_payment" Boolean DEFAULT true NOT NULL;

ALTER TABLE "public"."_instance"
    ADD COLUMN if not exists "payment_sequential_number" int DEFAULT 0 NOT NULL;

-- add some payment stuff to order
ALTER TABLE "public"."_order"
    ADD COLUMN if not exists "payment_status" Text;
ALTER TABLE "public"."_order"
    ADD COLUMN if not exists "payment_transaction_id" Text;

COMMIT;

-- version 0.6.2
BEGIN;

-- raw response / payload column should be named raw, we don’t know if it’s always going to be json
-- and we need an id to update this later
DROP TABLE IF EXISTS "public"."_payment_status_update";
CREATE TABLE "public"."_payment_status_update"
(
    "payment_status_update_id" BIGSERIAL PRIMARY KEY,
    "instance_id"              Integer                                NOT NULL,
    "raw"                      Text,
    "origin"                   Text,
    "bool_processed"           Boolean                  DEFAULT false NOT NULL,
    "date_processed"           Timestamp With Time Zone,
    "date_created"             Timestamp With Time Zone DEFAULT now() NOT NULL
);


ALTER TABLE "public"."_order"
    ADD COLUMN if not exists "payment_sequential_number" int;

COMMIT;

-- version 0.6.3
BEGIN;

ALTER TABLE "public"."_instance"
    ADD COLUMN if not exists "confirmation_copy_to" Character Varying(127);

COMMIT;

-- version 0.6.4
BEGIN;

-- we want to remember some stuff on the original payment if the processing succeeded
ALTER TABLE "public"."_payment_status_update"
    ADD COLUMN if not exists "amount" int;
ALTER TABLE "public"."_payment_status_update"
    ADD COLUMN if not exists "order_id" int;

COMMIT;

-- version 0.6.5
BEGIN;

ALTER TABLE "public"."_order"
    ADD COLUMN if not exists "emailed_order_confirmation_success" Boolean DEFAULT false NOT NULL;

-- let op truncate tables:
-- alles met payment
-- alles met order, order_variant etc.
--    order_number...

COMMIT;

-- version 0.6.6
BEGIN;

truncate table public._payment_status_update;
truncate table public._order_variant;
truncate table public._order_number;
truncate table public._order;

ALTER TABLE "public"."_instance"
    ADD COLUMN if not exists "payment_sequential_number" int DEFAULT 0 NOT NULL;

COMMIT;

-- version 0.6.7
BEGIN;

ALTER TABLE "public"."_instance"
    ADD COLUMN if not exists "postcode_nl_key" Character Varying(127);
ALTER TABLE "public"."_instance"
    ADD COLUMN if not exists "postcode_nl_secret" Character Varying(127);

COMMIT;

-- version 0.6.8
BEGIN;

ALTER TABLE "public"."_order"
    ADD COLUMN if not exists "payment_afterwards" Boolean DEFAULT false NOT NULL;
ALTER TABLE "public"."_order"
    ADD COLUMN if not exists "payment_afterwards_captured" Boolean DEFAULT false NOT NULL;


COMMIT;
-- version 0.6.9
BEGIN;

ALTER TABLE "public"."_order"
    ADD COLUMN if not exists "payment_afterwards_id" Text;
ALTER TABLE "public"."_order"
    ADD COLUMN if not exists "payment_afterwards_text" Text;


COMMIT;
-- version 0.6.10
BEGIN;

ALTER TABLE "public"."_order"
    DROP COLUMN if exists "payment_afterwards_id";
ALTER TABLE "public"."_order"
    DROP COLUMN if exists "payment_afterwards_text";
ALTER TABLE "public"."_order"
    ADD COLUMN if not exists "payment_tracking_id" Text;
ALTER TABLE "public"."_order"
    ADD COLUMN if not exists "payment_tracking_text" Text;

COMMIT;
-- version 0.6.11
BEGIN;

ALTER TABLE "public"."_instance"
    ADD COLUMN if not exists "park" Boolean DEFAULT FALSE NOT NULL;

COMMIT;
-- version 0.6.12
BEGIN;
COMMIT;
-- version 0.6.13
BEGIN;
COMMIT;
-- version 0.6.14
BEGIN;

ALTER TABLE "public"."_template"
    ADD COLUMN if not exists "date_published" Timestamp With Time Zone;
ALTER TABLE "public"."_instance"
    ADD COLUMN if not exists "date_published" Timestamp With Time Zone;

COMMIT;
-- version 0.6.15
BEGIN;

-- some e-mail related columns must be renamed after integrating sendgrid to avoid confusion
DO
$$

    BEGIN
        IF EXISTS(SELECT *
                  FROM information_schema.columns
                  WHERE table_name = '_instance'
                    and column_name = 'mailgun_default_sender')
        THEN
            ALTER TABLE "public"."_instance"
                RENAME COLUMN "mailgun_default_sender" TO "mail_verified_sender";
        END IF;
    END
$$;
DO
$$
    BEGIN
        IF EXISTS(SELECT *
                  FROM information_schema.columns
                  WHERE table_name = '_instance'
                    and column_name = 'mailgun_default_receiver')
        THEN
            ALTER TABLE "public"."_instance"
                RENAME COLUMN "mailgun_default_receiver" TO "mail_default_receiver";
        END IF;
    END
$$;


COMMIT;

-- version 0.6.16
BEGIN;

-- live setting for psp, convert unused online column to live_flag
DO
$$
    BEGIN
        IF EXISTS(SELECT *
                  FROM information_schema.columns
                  WHERE table_name = '_payment_service_provider'
                    and column_name = 'online')
        THEN
            ALTER TABLE "public"."_payment_service_provider"
                RENAME COLUMN "online" TO "live_flag";
        ELSE
            ALTER TABLE "public"."_payment_service_provider"
                ADD COLUMN if not exists "live_flag" Boolean DEFAULT FALSE NOT NULL;
        END IF;
    END
$$;

ALTER TABLE "public"."_order"
    ADD COLUMN if not exists "payment_live_flag" Boolean DEFAULT TRUE NOT NULL;


COMMIT;

-- version 0.7.0
BEGIN;
-- search function enhanced including stopwords, alternatives and fts etc.
DROP TABLE IF EXISTS "public"."_search_settings";
CREATE TABLE "public"."_search_settings"
(
    "search_settings_id" SERIAL PRIMARY KEY,
    "instance_id"        Integer                                  NOT NULL,
    "name"               Character Varying(127)                   NOT NULL,
    "slug"               Character Varying(127)                   NOT NULL,
    "template_id"        Integer                  DEFAULT 0       NOT NULL,
    "use_fts"            Boolean                  DEFAULT false   NOT NULL,
    "fts_language"       Text                     DEFAULT 'dutch' NOT NULL,
    "date_created"       Timestamp With Time Zone DEFAULT now()   NOT NULL,
    "date_updated"       Timestamp With Time Zone DEFAULT now()   NOT NULL,
    "deleted"            Boolean                  DEFAULT false   NOT NULL,
    "is_default"         Boolean                  DEFAULT false   NOT NULL,
    CONSTRAINT "unique_search_settings_id" UNIQUE ("search_settings_id")
);
DROP TABLE IF EXISTS "public"."_search_stopwords";
CREATE TABLE "public"."_search_stopwords"
(
    "search_stopwords_id" SERIAL PRIMARY KEY,
    "search_settings_id"  Integer                                NOT NULL,
    "instance_id"         Integer                                NOT NULL,
    "stopword"            Text,
    "date_created"        Timestamp With Time Zone DEFAULT now() NOT NULL,
    "date_updated"        Timestamp With Time Zone DEFAULT now() NOT NULL,
    "deleted"             Boolean                  DEFAULT false NOT NULL,
    CONSTRAINT "unique_search_stopwords_id" UNIQUE ("search_stopwords_id")
);
DROP TABLE IF EXISTS "public"."_search_alternatives";
CREATE TABLE "public"."_search_alternatives"
(
    "search_alternatives_id" SERIAL PRIMARY KEY,
    "search_settings_id"     Integer                                NOT NULL,
    "instance_id"            Integer                                NOT NULL,
    "alternative"            Text,
    "correct"                Text,
    "date_created"           Timestamp With Time Zone DEFAULT now() NOT NULL,
    "date_updated"           Timestamp With Time Zone DEFAULT now() NOT NULL,
    "deleted"                Boolean                  DEFAULT false NOT NULL,
    CONSTRAINT "unique_search_alternatives_id" UNIQUE ("search_alternatives_id")
);
DROP TABLE IF EXISTS "public"."_search_log";
CREATE TABLE "public"."_search_log"
(
    "search_log_id"      SERIAL PRIMARY KEY,
    "search_settings_id" Integer                                NOT NULL,
    "instance_id"        Integer                                NOT NULL,
    "search"             Text,
    "results"            Integer                                NOT NULL,
    "date_created"       Timestamp With Time Zone DEFAULT now() NOT NULL,
    "date_updated"       Timestamp With Time Zone DEFAULT now() NOT NULL,
    "deleted"            Boolean                  DEFAULT false NOT NULL,
    CONSTRAINT "unique_search_log_id" UNIQUE ("search_log_id")
);
-- add a search column to every element with relevant columns in lower case without accents
-- and put an fts index on it
ALTER TABLE "public"."cms_page"
    ADD COLUMN if not exists "ci_ai" Text;
ALTER TABLE "public"."cms_image"
    ADD COLUMN if not exists "ci_ai" Text;
ALTER TABLE "public"."cms_embed"
    ADD COLUMN if not exists "ci_ai" Text;
ALTER TABLE "public"."cms_file"
    ADD COLUMN if not exists "ci_ai" Text;
ALTER TABLE "public"."cms_brand"
    ADD COLUMN if not exists "ci_ai" Text;
ALTER TABLE "public"."cms_serie"
    ADD COLUMN if not exists "ci_ai" Text;
ALTER TABLE "public"."cms_product"
    ADD COLUMN if not exists "ci_ai" Text;
ALTER TABLE "public"."cms_variant"
    ADD COLUMN if not exists "ci_ai" Text;

-- trying to index the _cache table better, since the slugs are ALWAYS retrieved using instance_id as well
-- and digitalocean reports only 31% indexes used on main database
-- https://www.cybertec-postgresql.com/en/combined-indexes-vs-separate-indexes-in-postgresql/
DROP INDEX if exists "index_slug_cache";
CREATE INDEX if not exists "index_slug_i_cache" ON "public"."_cache" USING btree ("instance_id", "slug" Asc NULLS Last);
-- ok after this the indexes usage dropped to 28% so that’s fishy

COMMIT;

-- version 0.7.1

BEGIN;
-- update the default value, now that templates actually take these values into account
UPDATE _template
SET nested_max = 2;
COMMIT;
-- version 0.7.2

BEGIN;

-- special table of lockers with keys, used to validate requests from somewhere else and share info with them through the key
DROP TABLE IF EXISTS "public"."_key";
DROP TABLE IF EXISTS "public"."_locker";
CREATE TABLE "public"."_locker"
(
    "key"         Text,
    "instance_id" Integer                  NOT NULL,
    "information" Text,
    "valid_until" Timestamp With Time Zone NOT NULL,
    CONSTRAINT "unique_locker_key" UNIQUE ("key")
);
CREATE INDEX if not exists "index_locker_key" ON "public"."_locker" USING btree ("key" Asc NULLS Last);

COMMIT;

-- version 0.7.3

BEGIN;

-- trying my hand at indexes again..., to have more index scans vs sequential scans...
-- drop the index that caused index scans to drop from 31% to 28%... (ideal: 100%)
DROP INDEX if exists "index_slug_i_cache";
CREATE INDEX if not exists "index_slug_cache" ON "public"."_cache" USING btree ("slug" Asc NULLS Last);
CREATE INDEX if not exists "index_instance_id_cache" ON "public"."_cache" USING btree ("instance_id" Asc NULLS Last);
-- ok, this dropped from 28% tot 27%. Whaaat?

COMMIT;

-- version 0.7.4

BEGIN;

COMMIT;

-- version 0.7.5

BEGIN;

CREATE INDEX if not exists "index_slug_i_cache" ON "public"."_cache" USING btree ("instance_id", "slug" Asc NULLS Last);
REINDEX TABLE _cache;
-- leave the other indexes as well for now: index_slug_cache, index_instance_id_cache
COMMIT;

-- version 0.7.6

BEGIN;

-- create index on domain in _instance and _instance_domain tables
DROP INDEX if exists "index_domain_instance";
CREATE INDEX if not exists "index_domain_instance" ON "public"."_instance" USING btree ("domain" Asc NULLS Last);
DROP INDEX if exists "index_domain_instance_domain";
CREATE INDEX if not exists "index_domain_instance_domain" ON "public"."_instance_domain" USING btree ("domain" Asc NULLS Last);

-- create index on _sessionvars table, which has the slowest queries at the moment (jan 2021)
DROP INDEX if exists "index_session_id_sessionvars";
CREATE INDEX if not exists "index_session_id_sessionvars" ON "public"."_sessionvars" USING btree ("session_id" Asc NULLS Last);

-- allow 404 slug setting in instance
ALTER TABLE "public"."_instance"
    ADD COLUMN if not exists "not_found_slug" Text;
-- setting to allow items that are not in stock to be ordered or not
ALTER TABLE "public"."_instance"
    ADD COLUMN if not exists "not_in_stock_can_be_ordered" boolean not null default false;

COMMIT;

-- version 0.7.7

BEGIN;
-- these indexes are not used according to analyser
DROP INDEX if exists "index_slug_cache";
DROP INDEX if exists "index_instance_id_cache";


COMMIT;

-- version 0.7.8

BEGIN;

COMMIT;

-- version 0.7.9

BEGIN;

-- shoppingcart can sometimes duplicate its rows, this is a bug that needs to be addressed
-- but for now we will make it impossible in the database to begin with, so it cannot occur
ALTER TABLE "public"."_shoppinglist_variant"
    DROP CONSTRAINT IF EXISTS "unique__shoppinglist_variant_variant_id";
-- remove duplicates keeping the most recent one
-- https://stackoverflow.com/questions/1746213/how-to-delete-duplicate-entries
DELETE
FROM "public"."_shoppinglist_variant" sv1 USING "public"."_shoppinglist_variant" sv2
WHERE sv1.variant_id = sv2.variant_id
  AND sv1.shoppinglist_id = sv2.shoppinglist_id
  AND sv1.shoppinglist_variant_id < sv2.shoppinglist_variant_id;
-- enforce the constraint
ALTER TABLE "public"."_shoppinglist_variant"
    ADD CONSTRAINT "unique__shoppinglist_variant_variant_id" UNIQUE ("shoppinglist_id", "variant_id");

-- add addresses to user (account)
DROP TABLE IF EXISTS "public"."_address";
CREATE TABLE "public"."_address"
(
    "address_id"              Serial PRIMARY KEY,
    "instance_id"             Integer                                NOT NULL,
    "user_id"                 Integer                                NOT NULL,
    "address_name"            Character Varying(127),
    "address_company"         Character Varying(127),
    "address_postal_code"     Character Varying(127),
    "address_number"          Character Varying(127),
    "address_number_addition" Character Varying(127),
    "address_street"          Character Varying(127),
    "address_street_addition" Character Varying(127),
    "address_city"            Character Varying(127),
    "address_country_name"    Character Varying(127),
    "address_country_iso2"    Character Varying(2),
    "address_country_iso3"    Character Varying(3),
    "date_created"            Timestamp With Time Zone DEFAULT now() NOT NULL,
    "date_updated"            Timestamp With Time Zone DEFAULT now() NOT NULL,
    "deleted"                 Boolean                  DEFAULT false NOT NULL
);
CREATE INDEX if not exists "index_address_user_id_instance_id" ON "public"."_address" USING btree ("instance_id", "user_id" Asc NULLS Last);

ALTER TABLE "public"."_user"
    ADD COLUMN if not exists "phone" Character Varying(255);

ALTER TABLE "public"."_order"
    ADD COLUMN if not exists "newsletter_subscribe" Boolean DEFAULT false NOT NULL;

COMMIT;

-- version 0.8.0

BEGIN;
-- properties for the product (like color, size, grape, region, etc, make it hierarchical)
DROP TABLE IF EXISTS "public"."cms_property";
CREATE TABLE "public"."cms_property"
(
    "property_id"  Serial PRIMARY KEY,
    "instance_id"  Integer                                NOT NULL,
    "title"        Character Varying(127),
    "slug"         Character Varying(127),
    "excerpt"      Text,
    "content"      Text,
    "template_id"  Integer                  DEFAULT 0     NOT NULL,
    "ci_ai"        Text,
    "online"       Boolean                  DEFAULT false NOT NULL,
    "date_created" Timestamp With Time Zone DEFAULT now() NOT NULL,
    "date_updated" Timestamp With Time Zone DEFAULT now() NOT NULL,
    "deleted"      Boolean                  DEFAULT false NOT NULL
);

DROP TABLE IF EXISTS "public"."cms_property_value";
CREATE TABLE "public"."cms_property_value"
(
    "property_value_id" Serial PRIMARY KEY,
    "instance_id"       Integer                                NOT NULL,
    "title"             Character Varying(127),
    "slug"              Character Varying(127),
    "excerpt"           Text,
    "content"           Text,
    "template_id"       Integer                  DEFAULT 0     NOT NULL,
    "ci_ai"             Text,
    "online"            Boolean                  DEFAULT false NOT NULL,
    "date_created"      Timestamp With Time Zone DEFAULT now() NOT NULL,
    "date_updated"      Timestamp With Time Zone DEFAULT now() NOT NULL,
    "deleted"           Boolean                  DEFAULT false NOT NULL
);

-- link property values to properties
DROP TABLE IF EXISTS "public"."cms_property_value_x_variant";
DROP TABLE IF EXISTS "public"."cms_variant_x_property_value";
DROP TABLE IF EXISTS "public"."cms_property_x_property_value";
CREATE TABLE "public"."cms_property_x_property_value"
(
    "property_x_property_value_id" Serial PRIMARY KEY,
    "sub_property_id"              Integer                                NOT NULL,
    "property_value_id"            Integer                                NOT NULL,
    "o"                            SmallInt                 DEFAULT 1     NOT NULL,
    "sub_o"                        SmallInt                 DEFAULT 1     NOT NULL,
    "date_created"                 Timestamp With Time Zone DEFAULT NOW() NOT NULL,
    "date_updated"                 Timestamp With Time Zone DEFAULT NOW() NOT NULL,
    "online"                       Boolean                  DEFAULT false NOT NULL,
    "deleted"                      Boolean                  DEFAULT false NOT NULL,
    CONSTRAINT "unique_cms_property_x_property_value_id" UNIQUE ("property_x_property_value_id")
);

-- new 3 way table to link the properties
DROP TABLE IF EXISTS "public"."cms_variant_x_properties";
CREATE TABLE "public"."cms_variant_x_properties"
(
    "variant_x_properties_id" Serial PRIMARY KEY,
    "variant_id"              Integer                                NOT NULL,
    "property_id"             Integer                                NOT NULL,
    "property_value_id"       Integer                                NOT NULL,
    "x_value"                 varchar(40)              default ''    NOT NULL,
    "o"                       SmallInt                 DEFAULT 1     NOT NULL,
    "date_created"            Timestamp With Time Zone DEFAULT NOW() NOT NULL,
    "date_updated"            Timestamp With Time Zone DEFAULT NOW() NOT NULL,
    "online"                  Boolean                  DEFAULT false NOT NULL,
    "deleted"                 Boolean                  DEFAULT false NOT NULL,
    CONSTRAINT "unique_variant_x_properties_variant_x_properties_id" UNIQUE ("variant_x_properties_id")
);
DROP TABLE IF EXISTS "public"."cms_page_x_properties";
CREATE TABLE "public"."cms_page_x_properties"
(
    "page_x_properties_id" Serial PRIMARY KEY,
    "page_id"              Integer                                NOT NULL,
    "property_id"          Integer                                NOT NULL,
    "property_value_id"    Integer                                NOT NULL,
    "x_value"              varchar(40)              default ''    NOT NULL,
    "o"                    SmallInt                 DEFAULT 1     NOT NULL,
    "date_created"         Timestamp With Time Zone DEFAULT NOW() NOT NULL,
    "date_updated"         Timestamp With Time Zone DEFAULT NOW() NOT NULL,
    "online"               Boolean                  DEFAULT false NOT NULL,
    "deleted"              Boolean                  DEFAULT false NOT NULL,
    CONSTRAINT "unique_page_x_properties_page_x_properties_id" UNIQUE ("page_x_properties_id")
);


ALTER TABLE cms_variant
    DROP COLUMN if exists "ean";

ALTER TABLE cms_variant
    DROP COLUMN if exists "upc";

COMMIT;

-- version 0.8.1

BEGIN;

-- redirect table handles specific slugs (the term) that you want to supersede
DROP TABLE IF EXISTS "public"."_redirect";
CREATE TABLE "public"."_redirect"
(
    "redirect_id"  Serial PRIMARY KEY,
    "instance_id"  Integer                                NOT NULL,
    "term"         varchar(127)                           NOT NULL,
    "to_slug"      varchar(127)                           NOT NULL,
    "date_created" Timestamp With Time Zone DEFAULT NOW() NOT NULL,
    "date_updated" Timestamp With Time Zone DEFAULT NOW() NOT NULL,
    "online"       Boolean                  DEFAULT true  NOT NULL,
    "deleted"      Boolean                  DEFAULT false NOT NULL,
    CONSTRAINT "unique_redirect_redirect_id" UNIQUE ("redirect_id"),
    CONSTRAINT "unique_redirect_term_instance_id" UNIQUE ("term", "instance_id")
);

COMMIT;

-- version 0.8.2

BEGIN;

COMMIT;

-- version 0.8.3

BEGIN;

ALTER TABLE "public"."_cache"
    ADD COLUMN if not exists "variant_page" int not null default 1;

COMMIT;

-- version 0.8.4

BEGIN;

ALTER TABLE "public"."_template"
    ADD COLUMN if not exists "variant_page_size" int not null default 60;

COMMIT;

-- version 0.8.5

BEGIN;

ALTER TABLE "public"."_cache"
    ADD COLUMN if not exists "variant_page_json" text;

COMMIT;

-- version 0.8.6

BEGIN;

COMMIT;

-- version 0.8.7

BEGIN;

DROP INDEX if exists "index_cms_brand_i_slug";
CREATE INDEX "index_cms_brand_i_slug" ON "public"."cms_brand" USING btree ("instance_id", "slug" Asc NULLS Last);

DROP INDEX if exists "index_cms_embed_i_slug";
CREATE INDEX "index_cms_embed_i_slug" ON "public"."cms_embed" USING btree ("instance_id", "slug" Asc NULLS Last);

DROP INDEX if exists "index_cms_file_i_slug";
CREATE INDEX "index_cms_file_i_slug" ON "public"."cms_file" USING btree ("instance_id", "slug" Asc NULLS Last);

DROP INDEX if exists "index_cms_image_i_slug";
CREATE INDEX "index_cms_image_i_slug" ON "public"."cms_image" USING btree ("instance_id", "slug" Asc NULLS Last);

DROP INDEX if exists "index_cms_menu_i_slug";
CREATE INDEX "index_cms_menu_i_slug" ON "public"."cms_menu" USING btree ("instance_id", "slug" Asc NULLS Last);

DROP INDEX if exists "index_cms_page_i_slug";
CREATE INDEX "index_cms_page_i_slug" ON "public"."cms_page" USING btree ("instance_id", "slug" Asc NULLS Last);

DROP INDEX if exists "index_cms_product_i_slug";
CREATE INDEX "index_cms_product_i_slug" ON "public"."cms_product" USING btree ("instance_id", "slug" Asc NULLS Last);

DROP INDEX if exists "index_cms_property_i_slug";
CREATE INDEX "index_cms_property_i_slug" ON "public"."cms_property" USING btree ("instance_id", "slug" Asc NULLS Last);

DROP INDEX if exists "index_cms_property_value_i_slug";
CREATE INDEX "index_cms_property_value_i_slug" ON "public"."cms_property_value" USING btree ("instance_id", "slug" Asc NULLS Last);

DROP INDEX if exists "index_cms_serie_i_slug";
CREATE INDEX "index_cms_serie_i_slug" ON "public"."cms_serie" USING btree ("instance_id", "slug" Asc NULLS Last);

DROP INDEX if exists "index_cms_variant_i_slug";
CREATE INDEX "index_cms_variant_i_slug" ON "public"."cms_variant" USING btree ("instance_id", "slug" Asc NULLS Last);

COMMIT;

-- version 0.8.8

BEGIN;

COMMIT;

-- version 0.8.9

BEGIN;

COMMIT;

-- version 0.8.10

BEGIN;

COMMIT;

-- version 0.8.11

BEGIN;

DROP INDEX if exists "index_cms_variant_x_properties_property_value_id";
CREATE INDEX "index_cms_variant_x_properties_property_value_id" ON "public"."cms_variant_x_properties" USING btree ("property_value_id" Asc NULLS Last);

DROP INDEX if exists "index_cms_page_x_properties_property_value_id";
CREATE INDEX "index_cms_page_x_properties_property_value_id" ON "public"."cms_page_x_properties" USING btree ("property_value_id" Asc NULLS Last);

CREATE OR REPLACE FUNCTION peat_parse_float(my_input_value text, my_decimal_separator TEXT, my_radix TEXT)
    RETURNS FLOAT AS
$$
DECLARE
    return_value FLOAT DEFAULT NULL;
BEGIN
    BEGIN
        return_value := replace(replace(my_input_value, my_radix, ''), my_decimal_separator, '.')::FLOAT;
    EXCEPTION
        WHEN OTHERS THEN
            -- RAISE NOTICE 'Invalid float value: "%".  Returning NULL.', my_input_value;
            RETURN 0;
    END;
    RETURN return_value;
END;
$$ LANGUAGE plpgsql
    IMMUTABLE;

COMMIT;

-- version 0.8.12

BEGIN;

ALTER TABLE _order
    ADD COLUMN if not exists user_gender Character Varying(40) Default '' NOT NULL;
ALTER TABLE _order
    ADD COLUMN if not exists billing_address_gender Character Varying(40) Default '' NOT NULL;
ALTER TABLE _order
    ADD COLUMN if not exists shipping_address_gender Character Varying(40) Default '' NOT NULL;
ALTER TABLE _address
    ADD COLUMN if not exists address_gender Character Varying(40) Default '' NOT NULL;
ALTER TABLE _user
    ADD COLUMN if not exists gender Character Varying(40) Default '' NOT NULL;

COMMIT;

-- version 0.8.14

BEGIN;

ALTER TABLE cms_variant
    ADD COLUMN if not exists for_sale BOOLEAN DEFAULT true NOT NULL;

COMMIT;

-- version 0.8.15

BEGIN;

COMMIT;

-- version 0.8.16

BEGIN;

COMMIT;

-- version 0.8.17

BEGIN;

-- VAT groups w/ percentages
DROP TABLE IF EXISTS "public"."_vat_category";
CREATE TABLE "public"."_vat_category"
(
    "vat_category_id" Serial PRIMARY KEY,
    "instance_id"     Integer                                NOT NULL,
    "title"           Character Varying(127),
    "percentage"      Character Varying(127)                 NOT NULL,
    "o"               Integer                  DEFAULT 0     NOT NULL,
    "online"          Boolean                  DEFAULT false NOT NULL,
    "date_created"    Timestamp With Time Zone DEFAULT now() NOT NULL,
    "date_updated"    Timestamp With Time Zone DEFAULT now() NOT NULL,
    "deleted"         Boolean                  DEFAULT false NOT NULL
);

ALTER TABLE cms_variant
    ADD COLUMN if not exists vat_category_id integer DEFAULT 0 NOT NULL;

ALTER TABLE _order_variant
    ADD COLUMN if not exists vat_percentage float DEFAULT 21 NOT NULL;


COMMIT;

-- version 0.8.18

BEGIN;

-- petit clos update for template functionality
update cms_variant_x_properties
set o = 0
where property_id in
      (select property_id from cms_property where slug = 'wijnsoort');

COMMIT;

-- version 0.8.19

BEGIN;

-- css_class for property and property_value
ALTER TABLE cms_property
    ADD COLUMN if not exists css_class Character varying(255) DEFAULT '' NOT NULL;
ALTER TABLE cms_property_value
    ADD COLUMN if not exists css_class Character varying(255) DEFAULT '' NOT NULL;

COMMIT;

-- version 0.8.20

BEGIN;

ALTER TABLE _instance
    ADD COLUMN if not exists send_invoice_as_pdf BOOLEAN default FALSE NOT NULL;

ALTER TABLE _instance
    ADD COLUMN if not exists confirmation_of_payment BOOLEAN default FALSE NOT NULL;

ALTER TABLE _instance
    ADD COLUMN if not exists create_invoice BOOLEAN default FALSE NOT NULL;

ALTER TABLE _instance
    ADD COLUMN if not exists template_id_payment_confirmation Int DEFAULT 0 NOT NULL;

ALTER TABLE _order
    ADD COLUMN if not exists emailed_payment_confirmation BOOLEAN default FALSE NOT NULL;

ALTER TABLE _order
    ADD COLUMN if not exists emailed_payment_confirmation_success BOOLEAN default FALSE NOT NULL;

ALTER TABLE _order
    ADD COLUMN if not exists emailed_payment_confirmation_response Text;

COMMIT;

-- version 0.9.0

BEGIN;

update _order
set emailed_payment_confirmation         = TRUE,
    emailed_payment_confirmation_success = FALSE;

COMMIT;

-- version 0.9.1

BEGIN;

-- CREATE TABLE "cms_image_x_property_value" -----------------------------
DROP TABLE IF EXISTS "public"."cms_image_x_property_value";
CREATE TABLE "public"."cms_image_x_property_value"
(
    "image_x_property_value_id" Serial PRIMARY KEY,
    "sub_image_id"              Integer                                NOT NULL,
    "property_value_id"         Integer                                NOT NULL,
    "o"                         SmallInt                 DEFAULT 1     NOT NULL,
    "sub_o"                     SmallInt                 DEFAULT 1     NOT NULL,
    "date_created"              Timestamp With Time Zone DEFAULT NOW() NOT NULL,
    "date_updated"              Timestamp With Time Zone DEFAULT NOW() NOT NULL,
    "online"                    Boolean                  DEFAULT false NOT NULL,
    "deleted"                   Boolean                  DEFAULT false NOT NULL,
    CONSTRAINT "unique_cms_image_x_property_value_image_x_property_value_id" UNIQUE ("image_x_property_value_id")
);
;

-- CREATE TABLE "cms_embed_x_property_value" -----------------------------
DROP TABLE IF EXISTS "public"."cms_embed_x_property_value";
CREATE TABLE "public"."cms_embed_x_property_value"
(
    "embed_x_property_value_id" Serial PRIMARY KEY,
    "sub_embed_id"              Integer                                NOT NULL,
    "property_value_id"         Integer                                NOT NULL,
    "o"                         SmallInt                 DEFAULT 1     NOT NULL,
    "sub_o"                     SmallInt                 DEFAULT 1     NOT NULL,
    "date_created"              Timestamp With Time Zone DEFAULT NOW() NOT NULL,
    "date_updated"              Timestamp With Time Zone DEFAULT NOW() NOT NULL,
    "online"                    Boolean                  DEFAULT false NOT NULL,
    "deleted"                   Boolean                  DEFAULT false NOT NULL,
    CONSTRAINT "unique_cms_embed_x_property_value_embed_x_property_value_id" UNIQUE ("embed_x_property_value_id")
);
;

-- CREATE TABLE "cms_file_x_property_value" -----------------------------
DROP TABLE IF EXISTS "public"."cms_file_x_property_value";
CREATE TABLE "public"."cms_file_x_property_value"
(
    "file_x_property_value_id" Serial PRIMARY KEY,
    "sub_file_id"              Integer                                NOT NULL,
    "property_value_id"        Integer                                NOT NULL,
    "o"                        SmallInt                 DEFAULT 1     NOT NULL,
    "sub_o"                    SmallInt                 DEFAULT 1     NOT NULL,
    "date_created"             Timestamp With Time Zone DEFAULT NOW() NOT NULL,
    "date_updated"             Timestamp With Time Zone DEFAULT NOW() NOT NULL,
    "online"                   Boolean                  DEFAULT false NOT NULL,
    "deleted"                  Boolean                  DEFAULT false NOT NULL,
    CONSTRAINT "unique_cms_file_x_property_value_file_x_property_value_id" UNIQUE ("file_x_property_value_id")
);
;

COMMIT;

-- version 0.9.2

BEGIN;

ALTER TABLE cms_image
    ADD COLUMN if not exists src_tiny text;
ALTER TABLE cms_image
    ADD COLUMN if not exists width_tiny smallInt;
ALTER TABLE cms_image
    ADD COLUMN if not exists height_tiny smallInt;

ALTER TABLE cms_image
    ADD COLUMN if not exists src_huge text;
ALTER TABLE cms_image
    ADD COLUMN if not exists width_huge smallInt;
ALTER TABLE cms_image
    ADD COLUMN if not exists height_huge smallInt;

ALTER TABLE _instance
    ADD COLUMN if not exists template_id_internal_confirmation Int DEFAULT 0 NOT NULL;

COMMIT;

-- version 0.10.0

BEGIN;

ALTER TABLE cms_image
    ADD COLUMN if not exists date_processed TimeStamp With Time Zone;

ALTER TABLE cms_image
    ALTER COLUMN filename_saved DROP NOT NULL;
ALTER TABLE cms_file
    ALTER COLUMN filename_saved DROP NOT NULL;

COMMIT;

-- version 0.10.2

BEGIN;

COMMIT;

-- version 0.10.3

BEGIN;

DROP INDEX if exists "index_session_deleted";
CREATE INDEX "index_session_deleted" ON "public"."_session" USING btree ("deleted" Desc NULLS Last);

COMMIT;

-- version 0.10.4

BEGIN;

COMMIT;


-- version 0.10.5

BEGIN;

COMMIT;


-- version 0.10.6

BEGIN;

COMMIT;


-- version 0.10.7

BEGIN;

ALTER TABLE _system
    DROP CONSTRAINT IF EXISTS "unique__system_version";
ALTER TABLE _system
    ADD CONSTRAINT "unique__system_version" PRIMARY KEY ("version");

ALTER TABLE _system
    ADD COLUMN if not exists "cache_pointer_filter_filename" text;

COMMIT;


-- version 0.10.8

BEGIN;

ALTER TABLE "_instance"
    DROP COLUMN if exists "not_found_slug";

COMMIT;

-- version 0.10.9

BEGIN;

/* template retrieval is relatively slow and used a lot, optimize the table for reading a bit more */

DROP INDEX if exists "index__template_instance_id";
CREATE INDEX if not exists "index__template_instance_id" ON "public"."_template" USING btree ("instance_id" Asc NULLS Last);

DROP INDEX if exists "index__template_name";
CREATE INDEX if not exists "index__template_name" ON "public"."_template" USING btree ("name" Asc NULLS Last);

DROP INDEX if exists "index__template_element";
CREATE INDEX if not exists "index__template_element" ON "public"."_template" USING btree ("element" Asc NULLS Last);

ALTER TABLE _session
    ALTER COLUMN user_agent SET DATA TYPE Text;

DROP INDEX if exists "index__shoppinglist_user_id";
CREATE INDEX if not exists "index__shoppinglist_user_id" ON "public"."_shoppinglist" USING btree ("user_id" Asc NULLS Last);

COMMIT;

-- version 0.10.10

BEGIN;

DROP TABLE IF EXISTS _search_stopwords;

ALTER TABLE _search_log
    DROP COLUMN IF EXISTS search_settings_id;

ALTER TABLE _instance
    DROP COLUMN IF EXISTS postcode_nl_key;

ALTER TABLE _instance
    DROP COLUMN IF EXISTS postcode_nl_secret;

COMMIT;

-- version 0.10.11

BEGIN;

DROP TABLE IF EXISTS _search_settings;
CREATE TABLE "public"."_search_settings"
(
    "search_settings_id" SERIAL PRIMARY KEY,
    "instance_id"        Integer                                NOT NULL,
    "date_created"       Timestamp With Time Zone DEFAULT now() NOT NULL,
    "date_updated"       Timestamp With Time Zone DEFAULT now() NOT NULL,
    "deleted"            Boolean                  DEFAULT false NOT NULL,
    CONSTRAINT "unique_search_settings_id" UNIQUE ("search_settings_id")
);

COMMIT;

-- version 0.10.12

BEGIN;

ALTER TABLE _shoppinglist_variant
    DROP CONSTRAINT IF EXISTS "unique__shoppinglist_variant_variant_id";

DROP INDEX if exists "index_shoppinglist_id_shoppinglist_variant";
CREATE INDEX "index_shoppinglist_id_shoppinglist_variant" ON "public"."_shoppinglist_variant" USING btree ("shoppinglist_id" Asc NULLS Last);

COMMIT;

-- version 0.11.0

BEGIN;

-- comments and ratings...

DROP TABLE IF EXISTS cms_comment;
CREATE TABLE "public"."cms_comment"
(
    "comment_id"   SERIAL PRIMARY KEY,
    "reply_to_id"  Integer,
    "instance_id"  Integer                                NOT NULL,
    "user_id"      Integer                  default 0     NOT NULL,
    "admin_id"     Integer                  default 0     NOT NULL,
    "ip_address"   Character Varying(45),
    "reverse_dns"  Character Varying(255),
    "user_agent"   Text,
    "nickname"     Character Varying(255),
    "email"        Character Varying(255),
    "title"        Character Varying(255),
    "content"      Text,
    "rating"       float,
    "referer"      Character Varying(127)                 NOT NULL,
    "slug"         Character Varying(127)                 NOT NULL,
    "date_created" Timestamp With Time Zone DEFAULT now() NOT NULL,
    "date_updated" Timestamp With Time Zone DEFAULT now() NOT NULL,
    "online"       Boolean                  DEFAULT false NOT NULL,
    "deleted"      Boolean                  DEFAULT false NOT NULL,
    CONSTRAINT "unique_cms_comment_id" UNIQUE ("comment_id")
);

DROP TABLE IF EXISTS "public"."cms_comment_x_brand";
CREATE TABLE "public"."cms_comment_x_brand"
(
    "comment_x_brand_id" Serial PRIMARY KEY,
    "sub_comment_id"     Integer                                NOT NULL,
    "brand_id"           Integer                                NOT NULL,
    "o"                  SmallInt                 DEFAULT 1     NOT NULL,
    "sub_o"              SmallInt                 DEFAULT 1     NOT NULL,
    "date_created"       Timestamp With Time Zone DEFAULT NOW() NOT NULL,
    "date_updated"       Timestamp With Time Zone DEFAULT NOW() NOT NULL,
    "online"             Boolean                  DEFAULT false NOT NULL,
    "deleted"            Boolean                  DEFAULT false NOT NULL,
    CONSTRAINT "unique_cms_comment_x_brand_comment_x_brand_id" UNIQUE ("comment_x_brand_id")
);

DROP TABLE IF EXISTS "public"."cms_comment_x_page";
CREATE TABLE "public"."cms_comment_x_page"
(
    "comment_x_page_id" Serial PRIMARY KEY,
    "sub_comment_id"    Integer                                NOT NULL,
    "page_id"           Integer                                NOT NULL,
    "o"                 SmallInt                 DEFAULT 1     NOT NULL,
    "sub_o"             SmallInt                 DEFAULT 1     NOT NULL,
    "date_created"      Timestamp With Time Zone DEFAULT NOW() NOT NULL,
    "date_updated"      Timestamp With Time Zone DEFAULT NOW() NOT NULL,
    "online"            Boolean                  DEFAULT false NOT NULL,
    "deleted"           Boolean                  DEFAULT false NOT NULL,
    CONSTRAINT "unique_cms_comment_x_page_comment_x_page_id" UNIQUE ("comment_x_page_id")
);

DROP TABLE IF EXISTS "public"."cms_comment_x_product";
CREATE TABLE "public"."cms_comment_x_product"
(
    "comment_x_product_id" Serial PRIMARY KEY,
    "sub_comment_id"       Integer                                NOT NULL,
    "product_id"           Integer                                NOT NULL,
    "o"                    SmallInt                 DEFAULT 1     NOT NULL,
    "sub_o"                SmallInt                 DEFAULT 1     NOT NULL,
    "date_created"         Timestamp With Time Zone DEFAULT NOW() NOT NULL,
    "date_updated"         Timestamp With Time Zone DEFAULT NOW() NOT NULL,
    "online"               Boolean                  DEFAULT false NOT NULL,
    "deleted"              Boolean                  DEFAULT false NOT NULL,
    CONSTRAINT "unique_cms_comment_x_product_comment_x_product_id" UNIQUE ("comment_x_product_id")
);

DROP TABLE IF EXISTS "public"."cms_comment_x_serie";
CREATE TABLE "public"."cms_comment_x_serie"
(
    "comment_x_serie_id" Serial PRIMARY KEY,
    "sub_comment_id"     Integer                                NOT NULL,
    "serie_id"           Integer                                NOT NULL,
    "o"                  SmallInt                 DEFAULT 1     NOT NULL,
    "sub_o"              SmallInt                 DEFAULT 1     NOT NULL,
    "date_created"       Timestamp With Time Zone DEFAULT NOW() NOT NULL,
    "date_updated"       Timestamp With Time Zone DEFAULT NOW() NOT NULL,
    "online"             Boolean                  DEFAULT false NOT NULL,
    "deleted"            Boolean                  DEFAULT false NOT NULL,
    CONSTRAINT "unique_cms_comment_x_serie_comment_x_serie_id" UNIQUE ("comment_x_serie_id")
);

DROP TABLE IF EXISTS "public"."cms_comment_x_variant";
CREATE TABLE "public"."cms_comment_x_variant"
(
    "comment_x_variant_id" Serial PRIMARY KEY,
    "sub_comment_id"       Integer                                NOT NULL,
    "variant_id"           Integer                                NOT NULL,
    "o"                    SmallInt                 DEFAULT 1     NOT NULL,
    "sub_o"                SmallInt                 DEFAULT 1     NOT NULL,
    "date_created"         Timestamp With Time Zone DEFAULT NOW() NOT NULL,
    "date_updated"         Timestamp With Time Zone DEFAULT NOW() NOT NULL,
    "online"               Boolean                  DEFAULT false NOT NULL,
    "deleted"              Boolean                  DEFAULT false NOT NULL,
    CONSTRAINT "unique_cms_comment_x_variant_comment_x_variant_id" UNIQUE ("comment_x_variant_id")
);

DROP TABLE IF EXISTS "public"."cms_comment_x_image";
CREATE TABLE "public"."cms_comment_x_image"
(
    "comment_x_image_id" Serial PRIMARY KEY,
    "sub_comment_id"     Integer                                NOT NULL,
    "image_id"           Integer                                NOT NULL,
    "o"                  SmallInt                 DEFAULT 1     NOT NULL,
    "sub_o"              SmallInt                 DEFAULT 1     NOT NULL,
    "date_created"       Timestamp With Time Zone DEFAULT NOW() NOT NULL,
    "date_updated"       Timestamp With Time Zone DEFAULT NOW() NOT NULL,
    "online"             Boolean                  DEFAULT false NOT NULL,
    "deleted"            Boolean                  DEFAULT false NOT NULL,
    CONSTRAINT "unique_cms_comment_x_image_comment_x_image_id" UNIQUE ("comment_x_image_id")
);

DROP TABLE IF EXISTS "public"."cms_comment_x_embed";
CREATE TABLE "public"."cms_comment_x_embed"
(
    "comment_x_embed_id" Serial PRIMARY KEY,
    "sub_comment_id"     Integer                                NOT NULL,
    "embed_id"           Integer                                NOT NULL,
    "o"                  SmallInt                 DEFAULT 1     NOT NULL,
    "sub_o"              SmallInt                 DEFAULT 1     NOT NULL,
    "date_created"       Timestamp With Time Zone DEFAULT NOW() NOT NULL,
    "date_updated"       Timestamp With Time Zone DEFAULT NOW() NOT NULL,
    "online"             Boolean                  DEFAULT false NOT NULL,
    "deleted"            Boolean                  DEFAULT false NOT NULL,
    CONSTRAINT "unique_cms_comment_x_embed_comment_x_embed_id" UNIQUE ("comment_x_embed_id")
);

COMMIT;

-- version 0.11.1

BEGIN;
COMMIT;

-- version 0.12.0

BEGIN;

ALTER TABLE "public"."_system"
    ADD COLUMN IF NOT EXISTS "daemon_did" Character Varying(10);
ALTER TABLE "public"."_system"
    ADD COLUMN IF NOT EXISTS "daemon_last_alive" Timestamp With Time Zone;

DROP TABLE IF EXISTS "public"."_ci_ai";
CREATE TABLE "public"."_ci_ai"
(
    "ci_ai_id"    Serial PRIMARY KEY,
    "instance_id" Integer                NOT NULL,
    "ci_ai"       Text                   NOT NULL,
    "title"       Character Varying(127) NOT NULL,
    "slug"        Character Varying(127) NOT NULL,
    "type_name"   Character Varying(127) NOT NULL,
    "id"          Integer                NOT NULL,
    CONSTRAINT "unique__ci_ai_ci_ai_id" UNIQUE ("ci_ai_id")
);

CREATE INDEX "index__ci_ai_instance_id" ON "public"."_ci_ai" USING btree ("instance_id" Asc NULLS Last);

COMMIT;

-- version 0.13.1

BEGIN;

ALTER TABLE "public"."_instance"
    ADD COLUMN if not exists "mail_form_allowed_to" Text;

COMMIT;

-- version 0.14.0

BEGIN;

COMMIT;

-- version 0.15.0

BEGIN;

COMMIT;

-- version 0.15.1

BEGIN;

ALTER TABLE "public"."_ci_ai"
    ADD COLUMN if not exists "online" boolean DEFAULT true NOT NULL;

COMMIT;

-- version 0.16.0

BEGIN;

-- remove ci_ai from cms tables since we have the separate ci_ai table now
ALTER TABLE "public"."cms_brand"
    DROP COLUMN if exists "ci_ai";

ALTER TABLE "public"."cms_embed"
    DROP COLUMN if exists "ci_ai";

ALTER TABLE "public"."cms_file"
    DROP COLUMN if exists "ci_ai";

ALTER TABLE "public"."cms_image"
    DROP COLUMN if exists "ci_ai";

ALTER TABLE "public"."cms_page"
    DROP COLUMN if exists "ci_ai";

ALTER TABLE "public"."cms_product"
    DROP COLUMN if exists "ci_ai";

ALTER TABLE "public"."cms_property"
    DROP COLUMN if exists "ci_ai";

ALTER TABLE "public"."cms_property_value"
    DROP COLUMN if exists "ci_ai";

ALTER TABLE "public"."cms_serie"
    DROP COLUMN if exists "ci_ai";

ALTER TABLE "public"."cms_variant"
    DROP COLUMN if exists "ci_ai";

-- enlarge css_class column, older version of peatcms have this as varchar(45)
ALTER TABLE "public"."cms_brand"
    ALTER COLUMN css_class TYPE character varying(255);

ALTER TABLE "public"."cms_embed"
    ALTER COLUMN css_class TYPE character varying(255);

ALTER TABLE "public"."cms_file"
    ALTER COLUMN css_class TYPE character varying(255);

ALTER TABLE "public"."cms_image"
    ALTER COLUMN css_class TYPE character varying(255);

ALTER TABLE "public"."cms_page"
    ALTER COLUMN css_class TYPE character varying(255);

ALTER TABLE "public"."cms_product"
    ALTER COLUMN css_class TYPE character varying(255);

ALTER TABLE "public"."cms_property"
    ALTER COLUMN css_class TYPE character varying(255);

ALTER TABLE "public"."cms_property_value"
    ALTER COLUMN css_class TYPE character varying(255);

ALTER TABLE "public"."cms_serie"
    ALTER COLUMN css_class TYPE character varying(255);

ALTER TABLE "public"."cms_variant"
    ALTER COLUMN css_class TYPE character varying(255);

COMMIT;

-- version 0.16.1

BEGIN;

COMMIT;

-- version 0.16.2

BEGIN;

COMMIT;

-- version 0.16.3

BEGIN;

COMMIT;

-- version 0.16.4

BEGIN;

COMMIT;

-- version 0.17.0

BEGIN;

DROP TABLE IF EXISTS "public"."_history";
CREATE TABLE "public"."_history"
(
    "history_id"   Serial PRIMARY KEY,
    "instance_id"  Integer                                NOT NULL,
    "admin_id"     Integer                                NOT NULL,
    "user_id"      Integer                                NOT NULL,
    "admin_name"   character varying(255)                 NOT NULL,
    "user_name"    character varying(255)                 NOT NULL,
    "table_name"   character varying(255)                 NOT NULL,
    "table_column" character varying(255)                 NOT NULL,
    "key"          Integer                                NOT NULL,
    "value"        Text                                   NOT NULL,
    "date_created" Timestamp With Time Zone DEFAULT NOW() NOT NULL
);
CREATE INDEX if not exists "index_history_table_column" ON "public"."_history" USING btree ("instance_id", "table_column" Asc NULLS Last);
CREATE INDEX if not exists "index_history_table_name_key" ON "public"."_history" USING btree ("instance_id", "table_name", "key" Asc NULLS Last);

COMMIT;

-- version 0.18.0

BEGIN;

ALTER TABLE _history DROP COLUMN IF EXISTS history_id;

ALTER TABLE cms_image ADD COLUMN IF NOT EXISTS "static_root" character varying(255);

COMMIT;

-- version 0.18.1

BEGIN;

COMMIT;

-- version 0.19.0

BEGIN;

ALTER TABLE _instance
    ADD COLUMN if not exists turnstile_site_key Character Varying(127) Default '' NOT NULL;
ALTER TABLE _instance
    ADD COLUMN if not exists turnstile_secret_key Character Varying(127) Default '' NOT NULL;
ALTER TABLE _instance
    ADD COLUMN if not exists plausible_active Boolean Default false NOT NULL;
ALTER TABLE _instance
    ADD COLUMN if not exists plausible_events Boolean Default false NOT NULL;
ALTER TABLE _instance
    ADD COLUMN if not exists plausible_revenue Boolean Default false NOT NULL;

COMMIT;

-- version 0.19.1

BEGIN;

COMMIT;

-- version 0.19.2

BEGIN;

COMMIT;

-- version 0.19.3

BEGIN;

COMMIT;

-- version 0.20.0

BEGIN;

ALTER TABLE _instance
    ADD COLUMN if not exists myparcel_api_key Character Varying(127) Default '' NOT NULL;

ALTER TABLE _order
    ADD COLUMN if not exists myparcel_exported Boolean DEFAULT false NOT NULL,
    ADD COLUMN if not exists myparcel_exported_success Boolean DEFAULT false NOT NULL,
    ADD COLUMN if not exists myparcel_exported_uuid Text,
    ADD COLUMN if not exists myparcel_exported_date Timestamp With Time Zone;

UPDATE _order SET myparcel_exported = TRUE, myparcel_exported_uuid = 'Not yet implemented.', myparcel_exported_date = NOW() WHERE 1 = 1;

COMMIT;

-- version 0.20.1

BEGIN;

ALTER TABLE _order
    ADD COLUMN if not exists myparcel_exported_error Boolean DEFAULT false NOT NULL,
    ADD COLUMN if not exists myparcel_exported_response Text;

COMMIT;

-- version 0.20.2

BEGIN;

ALTER TABLE "public"."_instance"
    DROP COLUMN if exists "presentation_admin";
ALTER TABLE "public"."_instance"
    DROP COLUMN if exists "presentation_theme";

COMMIT;

-- version 0.21.0

BEGIN;

ALTER TABLE "public"."cms_serie"
    ADD COLUMN if not exists "date_published" Timestamp With Time Zone;

ALTER TABLE "public"."cms_product"
    ADD COLUMN if not exists "date_published" Timestamp With Time Zone;

ALTER TABLE "public"."_instance"
    ADD COLUMN if not exists "csp_default_src" Text;

-- (bugfix 0.21.0) remove size limit on x_value, previously this was varchar(40)
ALTER TABLE "public"."cms_page_x_properties"
    ALTER COLUMN x_value TYPE text;

ALTER TABLE "public"."cms_variant_x_properties"
    ALTER COLUMN x_value TYPE text;

COMMIT;

-- version 0.21.1

BEGIN;

COMMIT;

-- version 0.22.0

BEGIN;

ALTER TABLE "public"."_cache"
    ALTER COLUMN slug TYPE varchar(1024);

ALTER TABLE "public"."_instance"
    ADD COLUMN if not exists "payment_link_valid_hours" int DEFAULT 24 NOT NULL;

COMMIT;

-- version 0.22.1

BEGIN;

DROP TABLE IF EXISTS "public"."_instagram_feed";
DROP TABLE IF EXISTS "public"."_instagram_auth";
DROP TABLE IF EXISTS "public"."_instagram_media";

ALTER TABLE "public"."_instance"
    ADD COLUMN if not exists "homepage_slug" Character Varying(127);

COMMIT;

-- version 0.23.0

BEGIN;

ALTER TABLE "public"."_order"
    ADD COLUMN if not exists "vat_number" Character Varying(127);
ALTER TABLE "public"."_order"
    ADD COLUMN if not exists "vat_country_iso2" Character Varying(2);
ALTER TABLE "public"."_order"
    ADD COLUMN if not exists "vat_valid" BOOLEAN default FALSE not null;
ALTER TABLE "public"."_order"
    ADD COLUMN if not exists "vat_history" Text;

-- improve retrieving recent history for ‘poll’ request
CREATE INDEX if not exists "index_history_date_created" ON "public"."_history" USING btree ("instance_id", "date_created" Desc NULLS Last);

ALTER TABLE "public"."_shoppinglist_variant"
    ADD COLUMN if not exists "variant_slug" Character Varying(127);

COMMIT;

-- version 0.23.1

BEGIN;
COMMIT;

-- version 0.24.0

BEGIN;
COMMIT;

-- version 0.24.1

BEGIN;
COMMIT;

-- version 0.24.2

BEGIN;
COMMIT;

-- version 0.25.0

BEGIN;

ALTER TABLE "public"."_order"
    ADD COLUMN if not exists "cancelled" BOOLEAN default FALSE NOT NULL;

ALTER TABLE "public"."cms_variant"
    ADD COLUMN if not exists "quantity_in_stock" INTEGER;

CREATE TABLE _image_slug_history
(
    slug varchar(255) PRIMARY KEY,
    since timestamp with time zone DEFAULT now() NOT NULL
);
-- insert the processed image urls into the history table
INSERT INTO _image_slug_history (slug) SELECT DISTINCT(CONCAT(instance_id, '/', (REGEXP_MATCHES(src_small, '[^/]+(?=\.webp$)'))[1])) FROM cms_image;
-- for good measure insert the regular slugs as well
INSERT INTO _image_slug_history (slug)
SELECT CONCAT(instance_id, '/', slug) FROM cms_image
ON CONFLICT (slug) DO NOTHING;

COMMIT;

-- version 0.26.0

BEGIN;

-- improve retrieving history per user for ‘poll’ request
CREATE INDEX if not exists "index_history_user_id" ON "public"."_history" USING btree ("instance_id", "user_id" Desc NULLS Last);

-- make items hide from search and sitemap and so on
ALTER TABLE "public"."cms_brand"
    ADD COLUMN if not exists "can_be_found" BOOLEAN default TRUE NOT NULL;
ALTER TABLE "public"."cms_embed"
    ADD COLUMN if not exists "can_be_found" BOOLEAN default TRUE NOT NULL;
ALTER TABLE "public"."cms_file"
    ADD COLUMN if not exists "can_be_found" BOOLEAN default TRUE NOT NULL;
ALTER TABLE "public"."cms_image"
    ADD COLUMN if not exists "can_be_found" BOOLEAN default TRUE NOT NULL;
ALTER TABLE "public"."cms_page"
    ADD COLUMN if not exists "can_be_found" BOOLEAN default TRUE NOT NULL;
ALTER TABLE "public"."cms_product"
    ADD COLUMN if not exists "can_be_found" BOOLEAN default TRUE NOT NULL;
ALTER TABLE "public"."cms_property"
    ADD COLUMN if not exists "can_be_found" BOOLEAN default TRUE NOT NULL;
ALTER TABLE "public"."cms_property_value"
    ADD COLUMN if not exists "can_be_found" BOOLEAN default TRUE NOT NULL;
ALTER TABLE "public"."cms_serie"
    ADD COLUMN if not exists "can_be_found" BOOLEAN default TRUE NOT NULL;
ALTER TABLE "public"."cms_variant"
    ADD COLUMN if not exists "can_be_found" BOOLEAN default TRUE NOT NULL;

-- add shop addresses to instance
DROP TABLE IF EXISTS "public"."_address_shop";
CREATE TABLE "public"."_address_shop"
(
    "address_shop_id"         Serial PRIMARY KEY,
    "instance_id"             Integer                                NOT NULL,
    "is_pickup_address"       Boolean                  DEFAULT false NOT NULL,
    "address_name"            Character Varying(127),
    "address_postal_code"     Character Varying(127),
    "address_number"          Character Varying(127),
    "address_number_addition" Character Varying(127),
    "address_street"          Character Varying(127),
    "address_street_addition" Character Varying(127),
    "address_city"            Character Varying(127),
    "address_country_name"    Character Varying(127),
    "address_country_iso2"    Character Varying(2),
    "address_country_iso3"    Character Varying(3),
    "address_remarks"         Text,
    "o"                       Integer                  DEFAULT 1     NOT NULL,
    "date_created"            Timestamp With Time Zone DEFAULT now() NOT NULL,
    "date_updated"            Timestamp With Time Zone DEFAULT now() NOT NULL,
    "deleted"                 Boolean                  DEFAULT false NOT NULL
);
CREATE INDEX if not exists "index_address_shop_instance_id" ON "public"."_address_shop" USING btree ("instance_id" Asc);


COMMIT;

-- version 0.27.0

BEGIN;

DO $$
    BEGIN
        IF EXISTS(SELECT *
                  FROM information_schema.columns
                  WHERE table_name='_order' AND column_name='remarks_user')
        THEN
            ALTER TABLE "public"."_order"
                RENAME COLUMN "remarks_user" TO "shipping_remarks";
        END IF;
    END $$;

ALTER TABLE "public"."_order"
    DROP COLUMN if exists "remarks_admin";

ALTER TABLE "public"."_shoppinglist"
    DROP COLUMN if exists "remarks_user";

ALTER TABLE "public"."_shoppinglist"
    DROP COLUMN if exists "remarks_admin";

ALTER TABLE "public"."_order"
    ADD COLUMN if not exists "rating" float;

ALTER TABLE "public"."_order"
    ADD COLUMN if not exists "local_pickup" BOOLEAN DEFAULT false NOT NULL;

COMMIT;

-- version 0.27.1

BEGIN;

COMMIT;

-- version 0.28.0

BEGIN;

COMMIT;

-- version 0.29.0

BEGIN;

ALTER TABLE "public"."_instance"
    DROP COLUMN if exists "recaptcha_site_key";

ALTER TABLE "public"."_instance"
    DROP COLUMN if exists "recaptcha_secret_key";

ALTER TABLE "public"."_instance"
    DROP COLUMN if exists "recaptcha_pass_score";

COMMIT;

-- version 0.29.1

BEGIN;

COMMIT;

-- version 0.29.2

BEGIN;

COMMIT;

-- version 0.29.3

BEGIN;

DROP TABLE IF EXISTS "public"."_search_settings";

DROP TABLE IF EXISTS "public"."_payment";
CREATE TABLE "public"."_payment"
(
    "payment_id"   Character Varying(127) PRIMARY KEY     NOT NULL,
    "order_id"     Integer                                NOT NULL,
    "date_created" Timestamp With Time Zone DEFAULT now() NOT NULL
);

COMMIT;

-- version 0.30.0

BEGIN;

DROP TABLE IF EXISTS "public"."_admin_login_attempt";
CREATE TABLE "public"."_admin_login_attempt"
(
    "admin_login_attempt_id" Serial PRIMARY KEY                    NOT NULL,
    "domain"                 Character Varying(255)                 NOT NULL,
    "date_created"           Timestamp With Time Zone DEFAULT now() NOT NULL
);


ALTER TABLE _instance
    DROP COLUMN if exists plausible_active;
ALTER TABLE _instance
    DROP COLUMN if exists plausible_events;
ALTER TABLE _instance
    DROP COLUMN if exists plausible_revenue;
ALTER TABLE _instance
    ADD COLUMN if not exists umami_website_id text;

COMMIT;

-- version 0.31.0

BEGIN;

ALTER TABLE "public"."_history" ADD COLUMN if not exists "element_name" Character Varying(255);
ALTER TABLE "public"."_history" ADD COLUMN if not exists "element_id" Integer;

CREATE INDEX if not exists "index_history_which_element" ON "public"."_history" USING btree ("instance_id", "element_name", "element_id" Asc NULLS Last);

UPDATE "public"."_history" SET
    element_name = ltrim(replace(table_name, 'cms', ''), '_'),
    element_id = key
WHERE 1 = 1;

-- session restructuring:
TRUNCATE "public"."_session";

ALTER TABLE "public"."_session"
    DROP CONSTRAINT IF EXISTS "unique__session_token";
ALTER TABLE "public"."_session"
    DROP COLUMN if exists "token";
ALTER TABLE "public"."_session"
    DROP COLUMN if exists "token_hash";

ALTER TABLE "public"."_session" ADD COLUMN "token_hash" Character Varying(255);

ALTER TABLE "public"."_session"
    ADD PRIMARY KEY (token_hash);
-- end session restructuring

-- enlarge content type for files
ALTER TABLE "public"."cms_file"
    ALTER COLUMN "content_type" TYPE character varying(255);

COMMIT;

