# ---- Build Stage ----
FROM node:22.22.0-alpine3.22 AS builder

# console/package.json links against local sibling packages (../packages/*),
# so this stage needs the repo root as build context, not just console/.
WORKDIR /build

# Create the pnpm directory and set the PNPM_HOME environment variable
RUN mkdir -p ~/.pnpm
ENV PNPM_HOME=/root/.pnpm

# Set environment
ARG ENVIRONMENT=production

# Attribution
ARG DISABLE_FLEETBASE_ATTRIBUTION=false
ENV DISABLE_FLEETBASE_ATTRIBUTION=$DISABLE_FLEETBASE_ATTRIBUTION

# Add the pnpm global bin to the PATH
ENV PATH=/root/.pnpm/bin:$PATH

# Bring in the local workspace packages console links against
COPY packages ./packages

# Copy package metadata first for better dependency caching
COPY console/package.json console/pnpm-lock.yaml console/pnpm-workspace.yaml ./console/

# Copy over .npmrc if applicable
COPY console/.npmr[c] ./console/

# Install global dependencies
RUN npm install -g ember-cli pnpm

# Install git
RUN apk update && apk add git openssh-client

# Trust GitHub's RSA host key
RUN mkdir -p -m 0600 ~/.ssh && ssh-keyscan github.com >> ~/.ssh/known_hosts

# Each linked package (console links to these via link:../packages/*) has its
# own dependencies (e.g. ember-engines) that must be installed in place, since
# pnpm's link: protocol only symlinks the directory, it doesn't install deps.
RUN for pkg in customer-portal ember-core ember-ui; do \
      cd /build/packages/$pkg && pnpm install --frozen-lockfile; \
    done

WORKDIR /build/console

# Install app dependencies
RUN pnpm install --frozen-lockfile

# Copy the console directory contents into the container
COPY console/. .

# Build the application
RUN pnpm build --environment $ENVIRONMENT

# ---- Serve Stage ----
FROM nginx:alpine

# Copy the built app to our served directory
COPY --from=builder /build/console/dist /usr/share/nginx/html

# Expose the port nginx is bound to
EXPOSE 4200

# Use custom nginx.conf
COPY console/nginx.conf /etc/nginx/conf.d/default.conf

# Start Nginx server
CMD ["nginx", "-g", "daemon off;"]
