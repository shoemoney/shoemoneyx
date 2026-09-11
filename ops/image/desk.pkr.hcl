# ops/image/desk.pkr.hcl
#
# Builds the per-customer shoemoneyx desk AMI: Ubuntu 24.04, PHP 8.4, Node LTS,
# Redis, local MariaDB, nginx, the app cloned + built at image time, systemd
# units for desk:run/queue:work/schedule:work/reverb:start, and a first-boot
# hook that generates all secrets on the box itself. See ops/image/provision.sh
# for what actually happens and docs/HOSTED_IMAGE.md for the operator's guide.
#
# Run from the repo root:
#   packer init ops/image/desk.pkr.hcl
#   packer validate ops/image/desk.pkr.hcl
#   packer build ops/image/desk.pkr.hcl
packer {
  required_plugins {
    amazon = {
      version = ">= 1.3.0"
      source  = "github.com/hashicorp/amazon"
    }
  }
}

variable "region" {
  type    = string
  default = "us-east-1"
}

variable "instance_type" {
  type    = string
  default = "t3.small"
}

variable "aws_profile" {
  type    = string
  default = "default"
}

source "amazon-ebs" "desk" {
  profile       = var.aws_profile
  region        = var.region
  instance_type = var.instance_type
  ami_name      = "shoemoneyx-desk-{{timestamp}}"
  ssh_username  = "ubuntu"

  # Default VPC / default subnet for the region — no vpc_id/subnet_id pinned,
  # so this builds in whatever account/region it's pointed at.
  source_ami_filter {
    filters = {
      name                = "ubuntu/images/hvm-ssd-gp3/ubuntu-noble-24.04-amd64-server-*"
      root-device-type    = "ebs"
      virtualization-type = "hvm"
    }
    owners      = ["099720109477"] # Canonical
    most_recent = true
  }

  launch_block_device_mappings {
    device_name           = "/dev/sda1"
    volume_size           = 20
    volume_type           = "gp3"
    delete_on_termination = true
  }

  tags = {
    Project = "shoemoneyx"
    Name    = "shoemoneyx-desk"
    Built   = "{{timestamp}}"
  }
}

build {
  name    = "shoemoneyx-desk"
  sources = ["source.amazon-ebs.desk"]

  # file provisioner requires the destination directory to already exist
  # when the source has a trailing slash (copy contents, not the dir itself).
  provisioner "shell" {
    inline = ["mkdir -p /tmp/image-files"]
  }

  provisioner "file" {
    source      = "ops/image/files/"
    destination = "/tmp/image-files"
  }

  provisioner "shell" {
    execute_command = "sudo -S bash -c '{{ .Vars }} {{ .Path }}'"
    script          = "ops/image/provision.sh"
  }
}
