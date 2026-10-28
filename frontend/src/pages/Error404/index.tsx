import { __ } from "@wordpress/i18n";
import PageHeader from "@components/AppShell/PageHeader";
import Button from "@components/ui/Button";

export default function Error404() {
  return (
    <>
      <PageHeader
        title={__("Page not found", "mcpmate")}
        intro={__("This page doesn't exist.", "mcpmate")}
      />
      <Button variant="primary" href="#/">
        {__("Go to the Dashboard", "mcpmate")}
      </Button>
    </>
  );
}
